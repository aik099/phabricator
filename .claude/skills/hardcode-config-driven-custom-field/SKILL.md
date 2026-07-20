---
name: hardcode-config-driven-custom-field
description: How to convert an existing config-driven "standard" custom field (declared via `{app}.custom-field-definitions`) into a hardcoded PHP field class — for a field people are already using, so existing data must be preserved via migration. Covers writing the field class against the app's "stored" base class, a checkbox-edit-control label-duplication gotcha, the app-prefixed-key-vs-shared-key tradeoff when the same conceptual field needs to flow between two apps (e.g. Differential revision → Diffusion commit), the data migration for both the value-storage table and `herald_action` rows (including a multi-install "try every old raw key" pattern when different installs configured different admin-facing keys for the same field), and the `patch_status`/`bin/storage upgrade` gotcha where editing an autopatch file after it's already run once means it silently never re-runs. Use when a user wants to "hardcode"/"un-config-drive" an existing custom field, or asks why editing a `resources/sql/autopatches/*.php` file after running `bin/storage upgrade` didn't apply the new code. See also: `custom-field-storage-hashing` (the fieldIndex mechanism this migration depends on), `add-standard-custom-fields` (the opposite direction), `batch-custom-field-value-for-list-icon` (showing the resulting hardcoded field's value on a list view), `diffusion-herald-custom-field-actions` (Herald action wiring).
---

# Hardcoding a Config-Driven Custom Field

## Why hardcode at all, and when config-driven is actually the right call

Config-driven fields exist so an admin can add a field with no PHP deploy. But if a field is
conceptually fixed (its meaning, type, and existence shouldn't vary per install or be
admin-editable) and different installs happen to have configured it under **different raw
JSON keys** (e.g. one install used `rpi.co-authored-with-ai`, another used
`in-portal.co-authored-with-ai` for "the same" field), that divergence is itself a signal the field
should be hardcoded — a hardcoded field has one fixed key everywhere, eliminating the
"which key did this install use" question entirely. Don't reach for hardcoding just because a
field is simple, though: if per-install customizability (renaming, disabling, retyping without a
deploy) is a real requirement, config-driven remains correct — the migration below only pays off
if that flexibility was never actually being used.

## Writing the hardcoded field class

1. Extend the app's own **stored** base class (e.g. `PhabricatorCommitStoredCustomField`,
   `DifferentialStoredCustomField`) — these already provide
   `getValue()`/`setValue()`/`shouldUseStorage()`/`getValueForStorage()`/`setValueFromStorage()`
   plumbing; don't reinvent it on top of the plain `PhabricatorCustomField`/`{App}CustomField`
   base.
2. Give it a literal `getFieldKey()`. Existing hardcoded fields in the same app use a short
   `{app}:{name}` convention with **no** `std:` prefix (see `custom-field-storage-hashing` — that
   prefix exclusively marks the config-driven path), e.g. `diffusion:tags`,
   `diffusion:branches` (`PhabricatorCommitTagsField.php`, `PhabricatorCommitBranchesField.php`).
   Match that convention.
3. Register an instance in the app's `Phabricator{App}ConfigOptions.php` `$fields` array (the
   same array that seeds `{app}.fields` ordering) — sufficient for `PhutilClassMapQuery`-based
   discovery (`PhabricatorCustomField::buildFieldList()`) to pick it up automatically; no other
   wiring needed. `isFieldEnabled()`/`shouldDisableByDefault()` default to enabled.
4. Remove the old config option's JSON declaration/description only **after** the data migration
   below is written and tested — don't lose the admin's original raw key before you've captured
   it in the migration script.

### Checkbox edit control: don't duplicate the label

For a Bool field's `renderEditControl()`, mirror `PhabricatorStandardCustomFieldBool
::renderEditControl()` (`src/infrastructure/customfield/standard/PhabricatorStandardCustomFieldBool.php:82-91`)
closely:

```php
public function renderEditControl(array $handles) {
  return id(new AphrontFormCheckboxControl())
    ->setLabel($this->getFieldName())
    ->setCaption($this->getFieldDescription())
    ->addCheckbox(
      $this->getFieldKey(),
      1,
      null,               // <-- not $this->getFieldName() again
      (bool)$this->getValue());
}
```

`setLabel()` already sets the row's left-hand label. `addCheckbox()`'s 3rd argument is the
checkbox's own **inline** label (meant for multi-checkbox groups where each box needs its own
text) — passing the field name there too visibly duplicates the text next to a single yes/no
checkbox. Easy mistake by analogy to other `addCheckbox()` call sites that legitimately want
per-checkbox text.

## Cross-app value inheritance: don't force a shared key just to make a generic loop work

If the field's value needs to flow from one object to another (e.g. Differential revision → the
commit that closes it, via `PhabricatorRepositoryCommitMessageParserWorker
::inheritCustomFieldsFromRevision()`), and the two apps' hardcoded field keys are (correctly, per
the app-prefix convention) **different strings**, a generic "match fields by key across both
objects' full field lists" loop will never find a match. Don't try to force both fields to share
one literal key just to make a generic key-matching loop work — that fights the app-prefix
convention for no real benefit. Write the copy as an **explicit two-field pair** instead:

```php
private function inheritCustomFieldsFromRevision(
  DifferentialRevision $revision,
  PhabricatorRepositoryCommit $commit,
  PhabricatorUser $actor,
  $acting_as_phid) {

  $revision_field = id(new DifferentialCoAuthoredWithAIField())
    ->setViewer($actor)->setObject($revision);
  $commit_field = id(new PhabricatorCommitCoAuthoredWithAIField())
    ->setViewer($actor)->setObject($commit);

  id(new PhabricatorCustomFieldStorageQuery())
    ->addField($revision_field)->addField($commit_field)->execute();

  $new_value = $revision_field->getValueForStorage();
  if ($new_value === null) {
    return;
  }
  $old_value = $commit_field->getOldValueForApplicationTransactions();
  // ... apply via PhabricatorAuditTransaction / TYPE_CUSTOMFIELD as usual,
  // using $old_value/$new_value. This always re-syncs the commit's value to
  // the revision's on every parse — don't add a "commit already has a value,
  // skip" guard unless the field is genuinely meant to be independently
  // editable on the commit after the fact. If it isn't (the common case for
  // a field whose source of truth is really the revision), always applying
  // is correct, and reparsing is exactly what keeps them in sync.
}
```

## Data migration: value storage + Herald actions, per app, per install

Write **one** PHP autopatch under `resources/sql/autopatches/` — auto-discovered by filename, no
registration needed (unlike the older `resources/sql/patches/` +
`PhabricatorSQLPatchList.php`/`PhabricatorBuiltinPatchList.php` mechanism, which the codebase's own
comment says to stop using: `"NOTE: STOP! Don't add new patches here. Use
'resources/sql/autopatches/' instead!"`).

If the same conceptual field was configured with **different raw admin keys on different
installs**, loop over all known old raw keys and try each — rows only get touched if a `WHERE
fieldIndex = %s` actually matches, so trying an install-inapplicable key is a safe no-op. This
makes one script portable across every install with no per-install editing:

```php
<?php
// Rehashes storage rows from the old config-driven key to the new hardcoded key.
// Safe to run on any install unmodified: only matching rows are touched.

$old_raw_keys = array('rpi.co-authored-with-ai', 'in-portal.co-authored-with-ai');

$migrations = array(
  array('table' => new PhabricatorRepositoryCustomFieldStorage(),
        'namespace' => 'diffusion', 'new_key' => 'diffusion:co-authored-with-ai'),
  array('table' => new DifferentialCustomFieldStorage(),
        'namespace' => 'differential', 'new_key' => 'differential:co-authored-with-ai'),
);

foreach ($migrations as $migration) {
  $table = $migration['table'];
  $conn_w = $table->establishConnection('w');
  $new_index = PhabricatorHash::digestForIndex($migration['new_key']);

  foreach ($old_raw_keys as $old_raw_key) {
    $old_key = 'std:'.$migration['namespace'].':'.$old_raw_key;
    $old_index = PhabricatorHash::digestForIndex($old_key);

    queryfx($conn_w, 'UPDATE %T SET fieldIndex = %s WHERE fieldIndex = %s',
      $table->getTableName(), $new_index, $old_index);

    echo tsprintf("%s: %s -> %s (%d row(s))\n", $table->getTableName(),
      $old_key, $migration['new_key'], $conn_w->getAffectedRows());
  }
}

// herald_action stores "<field key>.<value>" as plain text (see custom-field-storage-hashing) —
// REPLACE() the key prefix, preserving the toggle-value suffix.
$action_table = new HeraldActionRecord();
$conn_w = $action_table->establishConnection('w');
foreach ($migrations as $migration) {
  foreach ($old_raw_keys as $old_raw_key) {
    $old_key = 'std:'.$migration['namespace'].':'.$old_raw_key;
    queryfx($conn_w,
      'UPDATE %T SET action = REPLACE(action, %s, %s) WHERE action LIKE %>',
      $action_table->getTableName(), $old_key, $migration['new_key'], $old_key);
    echo tsprintf("%s: %s -> %s (%d row(s))\n", $action_table->getTableName(),
      $old_key, $migration['new_key'], $conn_w->getAffectedRows());
  }
}
```

Notes:
- `%>` is a real `qsprintf`/`queryfx` conversion for a `LIKE` prefix match (confirmed precedent:
  `PhabricatorStorageManagementDestroyWorkflow.php:67`,
  `PhabricatorGarbageCollectorManagementCompactEdgesWorkflow.php:45`).
- `getAffectedRows()` is on `AphrontDatabaseConnection`
  (`libphutil/src/aphront/storage/connection/AphrontDatabaseConnection.php:18`) — log it after
  every `UPDATE` for a self-verifying migration.
- Verify via direct SQL, not just re-reading the code: `SELECT fieldIndex, COUNT(*) FROM {table}
  WHERE fieldIndex IN (old_hash, new_hash) GROUP BY fieldIndex` before and after.

### `bin/storage upgrade` won't re-run an autopatch you edited after it already ran

Applied patches are tracked **by filename alone**, in a `patch_status` table (database
`{namespace}_meta_data`, `PhabricatorStorageManagementAPI::TABLE_STATUS`,
`getAppliedPatches()`/`markPatchApplied()`). If you edit an autopatch file **after** it's already
been run once (e.g. adding the `herald_action` block to a script that already ran the
value-storage migration), `bin/storage upgrade` will **not** re-run it — the filename is already
marked applied, regardless of content changes. This fails silently: no error, the new code section
just never executes.

To force a re-run of the *updated* script:
```sql
DELETE FROM patch_status WHERE patch = '{namespace}:{filename}';
```
in that install's `{namespace}_meta_data` database, then re-run `bin/storage upgrade`. This is
safe even if part of the script already ran, as long as the earlier `UPDATE`s are idempotent (a
`WHERE fieldIndex = %s` that already migrated everything just matches zero rows the second time).

## Verification checklist

1. `php -l` every new/changed file; regenerate the symbol map if a class was added
   (`scripts/symbols/generate_php_symbols.php` needs a live DB — use `arc liberate <dir>` instead
   if unavailable; both may leave stray macOS `._*` AppleDouble files on `/Volumes`-style
   checkouts worth cleaning up).
2. Recompute `fieldIndex` by hand for both old and new keys (see `custom-field-storage-hashing`);
   confirm via direct SQL that rows exist at the new hash and not the old one, for every raw key
   tried, per table.
3. Confirm `herald_action` rows were rehashed too — separately verify, don't assume the same
   `UPDATE` covered both tables.
4. Load the object's edit page: field appears, checkbox state matches the DB row exactly (compare
   raw `fieldValue` via SQL, not just visual impression).
5. Load the object's detail/property page: a Bool field only renders when the stored value is
   truthy, by design (`PhabricatorCustomFieldList::appendFieldsToPropertyList()` skips rendering
   when the value renders as `null`) — don't test only against a currently-unchecked object and
   conclude the field is broken.
