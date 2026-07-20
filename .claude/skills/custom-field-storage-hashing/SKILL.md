---
name: custom-field-storage-hashing
description: How Phabricator custom field values and Herald actions are actually keyed in storage — the `fieldIndex = PhabricatorHash::digestForIndex($fieldKey)` hash used by every per-app `{app}_customfieldstorage` table, the `std:{namespace}:{config-json-key}` field-key format used specifically by config-driven "standard" fields (vs. the bare `{app}:{name}` convention used by hardcoded fields), and how `herald_action.action` embeds the field key as plain text (`"{fieldKey}.{toggleValue}"`). Use whenever the user asks how custom field values are stored/looked up, wants to compute a field's `fieldIndex` by hand, asks why renaming/changing a field key breaks existing data or Herald rules, or is debugging a custom field whose value "isn't found" despite existing in the DB (likely a fieldIndex mismatch, i.e. the code's `getFieldKey()` doesn't match what's actually stored). See also: `hardcode-config-driven-custom-field` (uses this hashing scheme to migrate a field's stored key), `batch-custom-field-value-for-list-icon` (uses the same storage query mechanism to batch-read one field across many objects), `diffusion-herald-custom-field-actions` (Herald action wiring for fields that stay config-driven).
---

# Custom Field Storage Hashing

## The core mechanism

Custom field values live in a per-app storage table (e.g. `repository_customfieldstorage`,
`differential_customfieldstorage`), with rows keyed by `(objectPHID, fieldIndex)`
(`src/infrastructure/customfield/storage/PhabricatorCustomFieldStorage.php:17-23`):

```
fieldIndex = PhabricatorHash::digestForIndex($fieldKey)
```

`digestForIndex()` (`src/infrastructure/util/PhabricatorHash.php:45-65`) takes the first 12 bytes
of a raw SHA1 of the field key string, mapping each byte through a 64-char alphabet
(`0-9a-zA-Z._`) via `ord(byte) & 0x3F`. The storage layer has **zero awareness** of whether a
field is config-driven or hardcoded, what type it is, or which app it belongs to — it only cares
about this hash. Any two fields (even in different apps, different tables) that happen to produce
the same `$fieldKey` string would collide in principle, though in practice the key format below
keeps them namespaced apart.

Compute it ad hoc, with no DB/`PhabricatorEnv` dependency (safe even in a sandbox where
`bin/*` scripts can't reach a live DB):

```bash
php -r '
function digestForIndex($s) {
  $hash = sha1($s, true);
  $map = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ._";
  $out = "";
  for ($i = 0; $i < 12; $i++) { $out .= $map[(ord($hash[$i]) & 0x3F)]; }
  return $out;
}
echo digestForIndex("diffusion:co-authored-with-ai")."\n";
'
```

## Field key format differs by whether the field is config-driven or hardcoded

- **Config-driven ("standard") fields**: the key is `std:{namespace}:{config-json-key}`
  (`PhabricatorStandardCustomField.php:45`, inside `buildStandardFields()`), where
  `{config-json-key}` is literally the JSON object key an admin chose in
  `{app}.custom-field-definitions` (e.g. `rpi.co-authored-with-ai`), and `{namespace}` comes from
  `getStandardCustomFieldNamespace()` on the app's multiplexer field class (e.g. `'diffusion'`,
  `'differential'` — these can differ per app even for a field an admin intends to be "the same"
  across apps, since each app declares its own namespace). **The admin never sees or types the
  full hashed/namespaced key** — only the bare JSON key. Don't write a config option description
  or ask a user for "the field key" without specifying which of these two strings you mean; they
  differ meaningfully (`rpi.co-authored-with-ai` vs. `std:diffusion:rpi.co-authored-with-ai`).
- **Hardcoded (bespoke `PhabricatorCustomField` subclass) fields**: whatever literal string
  `getFieldKey()` returns — existing examples use a short `{app}:{name}` convention with **no**
  `std:` prefix (that prefix is exclusively a marker of the config-driven path), e.g.
  `diffusion:tags`, `diffusion:branches` (`PhabricatorCommitTagsField.php`,
  `PhabricatorCommitBranchesField.php`).

Since the hash is a pure function of this string, **the single most common cause of a custom
field's value silently "not being found" despite existing in the DB is a fieldIndex mismatch** —
the currently-running code's `getFieldKey()` produces a different hash than what's actually
stored, most often because:
- A field's key was renamed/iterated on during development and the deployed code doesn't match
  the final string that was migrated to (or vice versa).
- Confusing the bare admin-facing key with the full namespaced key when writing lookup code.

This reproduces as "value never shows/persists, no matter what's actually in the DB" — visually
identical to a real rendering bug, a caching bug, or simply looking at the wrong object. Always
verify by computing both hashes directly (script above) and checking the DB (`SELECT * FROM
{table} WHERE fieldIndex IN (old_hash, new_hash)`) before assuming a code-logic bug elsewhere.

## Herald actions embed the field key as plain text, in a different table

`herald_action.action` (`src/applications/herald/storage/HeraldActionRecord.php`) stores
`"{fieldKey}.{toggleValue}"` as a literal string column (e.g.
`std:diffusion:rpi.co-authored-with-ai.1` — the `.1`/`.0` suffix is a Bool toggle-action's fixed
value; see `diffusion-herald-custom-field-actions` for why Bool fields split into two toggle
actions rather than one value-picker action). This is a **separate table with a separate storage
shape** from the value-storage tables above (no `fieldIndex` column, no hash — the key is embedded
directly in text, findable via `LIKE`). **Renaming a field's key breaks any Herald rule that
references the old key** — this table needs its own migration pass whenever a field key changes,
independent of migrating the value-storage table. It's easy to forget since it's a different table
with a completely different value shape (`action` text column vs. `fieldIndex`+`fieldValue`).

## Verification

1. Recompute the hash by hand for whatever key you believe is/should be in use.
2. `SELECT fieldIndex, COUNT(*) FROM {table} GROUP BY fieldIndex` (or filter `WHERE fieldIndex IN
   (...)` for specific candidate hashes) to see what's actually stored, rather than assuming.
3. `SELECT * FROM herald_action WHERE action LIKE '%{bare-key-substring}%'` to find any Herald
   rule rows referencing a field key, separately from the value-storage check above.
