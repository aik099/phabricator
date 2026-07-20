---
name: diffusion-diff-size-indicator
description: How the git-style "+++--  " diff-size indicator (originally Differential-only, on DifferentialRevisionListView) was ported to Diffusion commits — where the shared glyph/render logic lives (PhabricatorDiffSizeIconView), how added/removed line counts are computed and cached per-commit with no schema migration (PhabricatorRepositoryCommitLineCountParserWorker, reusing PhabricatorRepositoryCommitData's JSON commitDetails bag), and the deliberate product decision on which list views show it (PhabricatorAuditListView only, not DiffusionCommitListView, not the commit detail page) plus the PHUIHeaderView::addTag() type constraint that would block naive detail-page placement. Covers every gotcha hit computing the diff itself: phutil_units() doesn't support byte-size units at all, ArcanistDiffParser::parseDiff() throws on a genuinely empty diff (directory-only/property-only commits, or two Diffusion repos overlapping the same physical SVN tree), diffusion.rawdiffquery is VCS-agnostic and reusable from a worker via DiffusionQuery::callConduitWithDiffusionRequest(), and PhutilArgumentParser's flag-spelling autocorrection can silently "fix" a typo'd CLI flag rather than erroring. Use when wiring a size/stat indicator onto any PhabricatorRepositoryCommit or DifferentialRevision list view, when computing real diff content/line-counts for a commit outside of Differential's diff-attach flow, or when line-count/size data appears missing or wrong for specific commits. See also: repository-commit-import-pipeline, for the worker-chain-safety pattern this feature's backing worker follows.
---

# Diffusion Commit Diff-Size Indicator

## Shared rendering, not duplicated

Differential's existing indicator (`DifferentialRevisionListView::renderRevisionSize()`, CSS
classes `differential-revision-size`/`-small`/`-large` in `phui-oi-list-view.css` — tier boundary
is `plus_count <= 1` small / `>= 4` large / else default) was extracted rather than copy-pasted, so
both apps share one implementation:
`src/infrastructure/diff/view/PhabricatorDiffSizeIconView.php` — static
`getScaleGlyphs($add, $rem)` (fixed threshold table 20/50/150/375/1000/2500 total changed lines →
1-7 filled slots out of 7, proportioned between `+`/`-` with a rounding-correction loop) plus
instance `setAddedLineCount()`/`setRemovedLineCount()`/`render()` producing the tagged span (icons
+ CSS classes + tooltip). `DifferentialRevision::getRevisionScaleGlyphs()` now just delegates to
the shared static method — still needed because `DifferentialTransactionEditor` uses it directly
for feed text, independent of the list-view rendering path.

## Storage: no schema migration

`PhabricatorRepositoryCommit` has no line-count columns and isn't getting any — reuse the existing
free-form JSON bag on `PhabricatorRepositoryCommitData::commitDetails`
(`getCommitDetail($key, $default)`/`setCommitDetail($key, $value)`), keyed
`lines.added`/`lines.removed` (`PhabricatorRepositoryCommit::DETAIL_LINES_ADDED`/
`DETAIL_LINES_REMOVED`). `PhabricatorRepositoryCommit::hasLineCounts()` checks
`getCommitDetail(DETAIL_LINES_ADDED) !== null` — the computing worker **always writes both keys,
even when the value is `0`**, so a pure-deletion commit (0 added, N removed) correctly reads as
"has line counts, 0 added" rather than "no data yet". The check is presence-of-key, not
truthiness-of-value; don't "simplify" it to `if ($count)` or a pure-deletion commit's indicator
disappears.

## Computation: reuse diffusion.rawdiffquery, don't hand-roll VCS diffing

`PhabricatorRepositoryCommitLineCountParserWorker::loadLineCounts()` calls the existing
VCS-agnostic conduit method **in-process** (no HTTP) via:
```php
DiffusionQuery::callConduitWithDiffusionRequest($viewer, $drequest, 'diffusion.rawdiffquery', $params)
```
This dispatches by repo type to `DiffusionGitRawDiffQuery`/`DiffusionMercurialRawDiffQuery`/
`DiffusionSvnRawDiffQuery` — for SVN it runs `svn diff -r $(commit-1):$commit` under the hood, no
per-VCS code needed in the new worker at all. `HeraldCommitAdapter::loadCommitDiff()` uses this
exact same conduit method already, for the same reason (Herald needs a real diff too) — it's the
reference example to copy the calling pattern from.

Once you have the `filePHID` back from the conduit call, fetch it (`PhabricatorFileQuery`) and sum
**already-computed** per-hunk counts — `ArcanistDiffParser::parseDiff()` populates
`ArcanistDiffHunk::getAddLines()`/`getDelLines()` while parsing (character-by-character, in
`ArcanistDiffParser.php`'s hunk-parsing loop); don't re-count `+`/`-` from `getCorpus()` yourself.

## Gotchas hit building this (fixed in shipped code — don't rediscover these)

- **`phutil_units('8 MB in bytes')` throws.** `phutil_units()`
  (`libphutil/src/utils/utils.php:1073`) only converts `second(s)/minute(s)/hour(s)/day(s)` →
  `seconds`/`milliseconds`, and `byte(s)/bit(s)` → `bytes` — **no magnitude prefixes at all**, ever
  (no KB/MB/GB). It looks like a general unit converter from its one common idiom
  (`phutil_units('24 hours in seconds')`, used for lease times) but it categorically isn't one.
  Use a plain literal (`8 * 1024 * 1024`) for any byte-size parameter.
- **`ArcanistDiffParser::parseDiff('')` throws** `Exception: Can't parse an empty diff!`. A
  genuinely empty diff is a normal, valid VCS event (SVN directory-only add, property-only
  change), not an error condition — guard before calling the parser:
  ```php
  if (!strlen(trim($raw_diff))) { return array(0, 0); }
  ```
- **Two Diffusion "repository" records can legitimately track the same underlying physical SVN
  repository at different subpaths** (e.g. one at the tree root, one at a branch subdirectory) —
  the same SVN revision number then exists validly in both. If no `path` param is passed to
  `rawdiffquery`, the diff is computed against each repo's whole tracked subtree, so identical
  line counts across the two repos' view of "the same" revision number is expected overlap when
  the changed file falls within both trees, not a cross-repository data bug. Confirm with
  `svn log -v -c <rev>` / diff content comparison before suspecting a caching/dedup bug.
- **`PhutilArgumentParser` autocorrects near-miss CLI flags** (`PhutilArgumentSpellingCorrector`)
  — a single close match (e.g. `--line-count` typed against a declared `linecount` flag) is
  silently accepted with a logged "(Assuming ... is the British spelling of ...)" note, rather
  than erroring. A command that "worked" doesn't confirm the flags typed were spelled as declared;
  check the actual declared `'name'` value if a flag's effect seems to be missing.

## Where it renders — a product decision, not a default to copy blindly

Differential's own indicator only ever appears in `DifferentialRevisionListView` — never on the
revision detail page. For Diffusion, the explicit decision made in this codebase: render **only**
in `PhabricatorAuditListView` (the Audit app's "Recent"/triage list — where an auditor decides
whether a change is quick or heavy *before* opening it) and **not** in `DiffusionCommitListView`
(repository-home "Recent Commits", profile commit tabs) or the single-commit detail page
(`DiffusionCommitController`) — reasoning given: "no point showing size on a page where you can
already see the diff yourself; the point is estimating review time before you open it." Insertion
point in `PhabricatorAuditListView::buildList()`: as the **first** `$item->addAttribute(...)` call
(right after `setDisabled()`, before the fix/fixed-by/AI icons), matching where Differential's
list places it (first attribute, before branch/reviewers) — check attribute ordering against the
Differential reference render if adding this to yet another list, it's a visual detail users
notice.

If asked to add this indicator to a *new* list view, ask which existing list it should behave like
rather than assuming "everywhere Differential shows it" or "everywhere a commit list exists" —
this was explicitly narrowed down twice in review (added to `DiffusionCommitListView` first, then
explicitly removed once the audit-list-only intent was clarified).

`PHUIHeaderView::addTag()` (used on the commit detail page's own header) strictly requires a
`PHUITagView` instance — `PhabricatorDiffSizeIconView::render()`'s output (a raw
`javelin_tag('span', ...)`) can't be passed to it directly. `PHUIObjectItemView::addAttribute()`
(used by every list view above) accepts arbitrary renderable content instead, which is why list
views were the easy insertion point and the detail-page header wasn't.

## Backfilling existing commits

The computing worker (`PhabricatorRepositoryCommitLineCountParserWorker`) follows the safe
side-queue pattern from `repository-commit-import-pipeline` — its flag isn't in `IMPORTED_ALL`, so
existing commits need an explicit reparse pass:
```
bin/repository reparse --all <CALLSIGN> --linecount
```
per repository (loop over `bin/repository list`'s monogram output). New commits get it queued
automatically going forward with no extra action.
