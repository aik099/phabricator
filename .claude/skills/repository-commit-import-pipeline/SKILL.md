---
name: repository-commit-import-pipeline
description: How Diffusion's per-commit import pipeline is wired (message/change/owners/herald as a bitmask-flag chain of PhabricatorRepositoryCommitParserWorker subclasses via unconditional chain-forward queueTask() calls), the exact footgun in adding a new step as a link in that chain (a bug in the new step can permanently block Owners/Herald for every commit, including already-imported old ones, since repository discovery re-walks the whole chain on every pass), and the safe pattern for adding an optional enrichment step instead (independent side-queue task, its own bit kept out of IMPORTED_ALL/isImported()). Also covers bin/repository reparse's --importing (auto-chains) vs explicit --<step> (deliberately does not auto-chain) semantics, and bin/repository list's actual output format. Use when adding any new commit-import worker/step, or when a commit is stuck reporting "Still Importing" despite looking otherwise complete. See also: diffusion-diff-size-indicator, which was built using the safe pattern described here and hit this exact footgun before being fixed.
---

# Diffusion Commit Import Pipeline: Adding Enrichment Steps Safely

## The chain, as it actually works

`PhabricatorRepositoryCommit::importStatus` is a bitmask
(`src/applications/repository/storage/PhabricatorRepositoryCommit.php`):

```php
const IMPORTED_MESSAGE = 1;
const IMPORTED_CHANGE  = 2;
const IMPORTED_OWNERS  = 4;
const IMPORTED_HERALD  = 8;
const IMPORTED_ALL     = 15;   // 1|2|4|8 — isImported() checks ONLY this mask
```

`isImported()` → `isPartiallyImported(IMPORTED_ALL)` → `(importStatus & 15) == 15`. This bit gates
real UI/behavior: `DiffusionCommitController` ("Still Importing..." panel + hides the file/change
list), `PhabricatorAuditListView`/`DiffusionCommitListView` ("(Importing Commit...)" text),
Harbormaster's `HarbormasterWaitForPreviousBuildStepImplementation` (blocks builds on unimported
parents).

Each step is a `PhabricatorRepositoryCommitParserWorker` subclass
(`src/applications/repository/worker/`). The base class's `parseCommit()` pattern
(`PhabricatorRepositoryCommitChangeParserWorker.php`, `PhabricatorRepositoryCommitOwnersWorker.php`):

```php
protected function parseCommit($repository, $commit) {
  if (!$this->shouldSkipImportStep()) {
    // ... do the actual work, then:
    $commit->writeImportStatusFlag($this->getImportStepFlag());
  }
  if ($this->shouldQueueFollowupTasks()) {   // false only for explicit "--step" reparse (see below)
    $this->queueTask('NextWorkerInChain', array('commitID' => $commit->getID()));
  }
}
```

`shouldSkipImportStep()` only skips the **work**, not the **chain-forward call** — every commit
that gets re-walked (repository discovery re-runs the whole chain periodically, even for
already-imported commits) re-triggers the `queueTask()` at the end of every step regardless of
whether that step's own flag was already set.

## The footgun: a chain link that can throw

If a new step is inserted **as a link** in this chain — i.e. its own `parseCommit()` does
`queueTask('NextStep', ...)` only after its own work succeeds — then any exception in that step's
work permanently blocks every step after it, for **every** commit that passes through, including
old ones that discovery re-walks. Concretely: wiring `Change → NewStep → Owners → Herald` and
having `NewStep` throw means the `queueTask('...OwnersWorker', ...)` call is physically
unreachable code past the throw point. Already-fully-imported commits then start showing "Still
Importing" again, because discovery re-walks their chain and gets stuck at the new broken link.

**The fix — never make new enrichment optional-but-blocking.** Two independent changes:

1. Give the new step's flag its own bit, but **keep it out of `IMPORTED_ALL`** (comment this
   explicitly at the constant, it's not obvious from the value alone):
   ```php
   const IMPORTED_ALL = 15;              // unchanged — do not add new bits here
   const IMPORTED_NEWSTEP = 16;          // NOT in IMPORTED_ALL: optional, must never block isImported()
   ```
2. Queue the new step as an **independent side-task**, not a chain link — have the *existing*
   step that used to be the trigger point queue both the old next-step and the new step directly:
   ```php
   // e.g. PhabricatorRepositoryCommitChangeParserWorker::finishParse()
   protected function finishParse() {
     if ($this->shouldQueueFollowupTasks()) {
       $this->queueTask('PhabricatorRepositoryCommitOwnersWorker', array('commitID' => $commit->getID()));
       $this->queueTask('PhabricatorRepositoryCommitNewStepWorker', array('commitID' => $commit->getID()));
     }
   }
   ```
   The new worker's own `parseCommit()` does its work and sets its flag — full stop, no
   `queueTask()` at all. A bug in it can now only ever fail to produce its own output; it cannot
   block anything else. Side effect worth knowing: the two queued tasks now run truly
   concurrently/out-of-order (no dependency), not sequentially as a single-chain read would
   suggest — fine as long as the new step's output isn't consumed by any *other* step in the
   chain (verify this explicitly before relying on it).

Only bother with this whole pattern when the new step involves running non-trivial/unproven code
(network calls, subprocess diffs, parsers) — that's exactly the kind of code likely to throw in
ways you haven't hit yet.

## `bin/repository reparse` semantics that matter here

`--importing` auto-detects each commit's first *incomplete* step (walking the flags in
declaration order) and lets the normal chain continue from there — safe for genuinely-stuck
imports.

Explicit `--<step>` flags (e.g. `--message`, `--change`, `--owners`, `--herald`) run **only** the
named step(s) and set `'only' => true` on the queued task's data, which makes
`shouldQueueFollowupTasks()` return `false` — by design, this does **not** auto-chain to the next
step. If you tell someone to run `--owners` alone to unstick something, Herald will never get
auto-queued afterward; you need `--owners --herald` together, or `--importing`, or a separate
`--herald` pass.

Any step whose bit isn't in `IMPORTED_ALL` (per the pattern above) is invisible to
`--importing`/`withImporting(true)` (both filter on `IMPORTED_ALL`) — backfilling such a step for
existing commits always requires its own explicit `--<step>` reparse pass, deliberately, since
it's optional enrichment rather than a completeness gate.

`bin/repository list` takes no arguments at all (`PhabricatorRepositoryManagementListWorkflow`)
and just prints each repo's monogram (`rINP`, `rH`, ...) one per line — no `--format` flag exists.
`PhabricatorRepositoryQuery::withIdentifiers()` (used by `reparse --all`) accepts that monogram
form directly (`r[A-Z]+` pattern, `PhabricatorRepositoryQuery.php:70`), so loop over the raw
output with no parsing needed:
```bash
for r in $(bin/repository list); do bin/repository reparse --all "$r" --<step>; done
```

## Verification

- Decode `importStatus` by hand to see exactly which steps ran and which didn't:
  `1047 = 1024(CLOSEABLE) + 16(some optional bit) + 4(OWNERS) + 2(CHANGE) + 1(MESSAGE)`, missing
  `8(HERALD)` — `python3 -c "print(bin(1047))"` and match bits against the constants.
- To force a specific stuck/backlogged task to run immediately (ignores lease/backoff — safe for
  idempotent steps): `bin/worker execute --class <TaskClass> --min-failure-count 1`, or
  `--id <id>` for one task.
- Read the actual daemon log (`./bin/phd log --id <daemon-id>`) for the real exception text before
  guessing at a root cause from symptoms alone.
