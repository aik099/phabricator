---
name: add-standard-custom-fields
description: Adds config-driven "standard custom fields" support (an `<app>.custom-field-definitions` config option, admin-defined via JSON, no PHP required per field) to a given Phabricator application, following the pattern already used by Diffusion (`diffusion.custom-field-definitions`), Maniphest, Projects, Users, and Owners. Use when the user asks to add this feature to an application (e.g. "add standard custom fields to Differential") that doesn't have it yet. Takes the Phabricator application name (e.g. `Differential`, `Ponder`, `Phriction`) as a mandatory argument. See also: `diffusion-herald-custom-field-actions`, for making already-existing standard custom fields settable from Herald rule actions (a separate, later concern from adding the fields themselves).
---

# Add Standard Custom Fields to a Phabricator Application

Mandatory argument: the Phabricator application name in TitleCase (e.g. `Differential`).
If it's missing, ask for it before doing anything else — every step below depends on it.
Call the app name `{App}` (e.g. `Differential`) and its lowercase config prefix `{app}`
(e.g. `differential`) throughout.

## 0. Check it isn't already there

`grep -rn "{app}.custom-field-definitions"` across `src/`. If a config option and a
`*ConfiguredCustomField.php` already exist, stop and report that — don't duplicate it.

## 1. Find the application's custom-field plumbing

You need three things before writing any code — find them with `grep`/`find`, don't guess:

1. **The app's abstract custom field base class** — e.g. `DifferentialCustomField`,
   `PhabricatorCommitCustomField`, `ManiphestCustomField`. This is whatever
   `{App}{Object}::getCustomFieldBaseClass()` returns (find the main storage/object class
   for the app, e.g. `DifferentialRevision`, `PhabricatorRepositoryCommit`, and read that
   method plus the neighboring `getCustomFieldSpecificationForRole($role)`, which names the
   config key used for field ordering, e.g. `differential.fields`).
2. **Where concrete field classes for this app actually live** — `find` for a couple of
   existing `*Field.php` classes under the app's directory and check which subdirectory they're
   in (conventions vary: Diffusion uses `customfield/`, Differential mixes `customfield/` and
   `field/` — don't assume, check).
3. **Whether generic custom-field storage classes already exist for this app** —
   `find src/applications/{app_dir} -iname "*CustomFieldStorage*" -o -iname "*CustomFieldStringIndex*" -o -iname "*CustomFieldNumericIndex*"`.
   Most mature applications already have these (created for their own hand-written stored
   fields) — reuse them. Only if none exist will you need to create the three storage classes
   *and* a new SQL patch (see step 5).

## 2. Add the config option

In the app's `Phabricator{App}ConfigOptions.php` (`getOptions()`), add, right next to the
existing `{app}.fields` ordering option:

```php
$fields_example = array(
  'mycompany.estimated-hours' => array(
    'name' => pht('Estimated Hours'),
    'type' => 'int',
    'caption' => pht('Estimated number of hours this will take.'),
  ),
);
$fields_json = id(new PhutilJSON())->encodeFormatted($fields_example);
...
$this->newOption('{app}.custom-field-definitions', 'wild', array())
  ->setSummary(pht('Custom {App} fields.'))
  ->setDescription(
    pht(
      'Array of custom fields for {App}. For details on adding custom '.
      'fields to {App}, see "Configuring Custom Fields" in the '.
      'documentation.'))
  ->addExample($fields_json, pht('Valid setting')),
```

Do **not** add this new option's key to the `$fields`/`$default_fields` array that seeds
`{app}.fields` — the multiplexer field below is auto-discovered via the classmap and doesn't
need to be pre-listed there (confirmed against Diffusion's config, which omits it too).

## 3. Write the configured/multiplexer field class

Reference implementation: `src/applications/repository/customfield/PhabricatorCommitConfiguredCustomField.php`.

Create `{App}ConfiguredCustomField.php` in the concrete-fields directory you found in step 1:

```php
final class {App}ConfiguredCustomField
  extends {AppCustomFieldBaseClass}
  implements PhabricatorStandardCustomFieldInterface {

  public function getStandardCustomFieldNamespace() {
    return '{app}';
  }

  public function createFields($object) {
    $config = PhabricatorEnv::getEnvConfig('{app}.custom-field-definitions');

    return PhabricatorStandardCustomField::buildStandardFields(
      $this,
      $config);
  }

}
```

No changes are needed to `getCustomFieldBaseClass()`/`getCustomFieldSpecificationForRole()` —
classmap discovery (`PhutilClassMapQuery` in `PhabricatorCustomField::buildFieldList()`) finds
any new subclass of the app's base class automatically.

## 4. Add storage support — the step that's easy to get wrong

`buildStandardFields()` returns clones of **this template class** with `setProxy($standard)`
applied (`PhabricatorStandardCustomField.php:47-58`) — the object the rest of the app
interacts with is literally an instance of `{App}ConfiguredCustomField`, wearing the real
standard-field type (bool/text/int/select/...) as its proxy. Most `PhabricatorCustomField`
methods auto-forward to that proxy, but a few are **deliberately never proxied**, per the
comment in `PhabricatorCustomField.php` ("NOTE: This intentionally isn't proxied, to avoid
call cycles") — these must be implemented directly on `{App}ConfiguredCustomField}`:

```php
public function newStorageObject() {
  return new {App}CustomFieldStorage();
}

protected function newStringIndexStorage() {
  return new {App}CustomFieldStringIndex();
}

protected function newNumericIndexStorage() {
  return new {App}CustomFieldNumericIndex();
}
```

Use the storage classes found in step 1. If the app's base class (e.g.
`PhabricatorCommitCustomField`) already defines these three methods itself, skip this — every
subclass inherits them for free and you don't need to repeat them.

**Why this template+proxy split exists at all** (not obvious from reading the code, worth
understanding before touching it): it's avoiding a combinatorial subclass explosion across two
independent axes of variation, not a lazy-loading/access-control "Proxy pattern" in the GoF
sense. Which application a field belongs to (Diffusion vs. Maniphest vs. ...) determines
*storage* (table, PHID role); what kind of value it holds (bool/text/int/select/...) determines
*UI/Herald/coercion behavior*, and is entirely app-agnostic - the same
`PhabricatorStandardCustomFieldBool` instance behaves identically whether it's attached to a
commit or a task. PHP has no multiple inheritance, so combining both axes via a class hierarchy
alone would require one subclass per (app x type) combination. Composition sidesteps that: **N**
app-specific template classes (one per app; already needed anyway for that app's own bespoke
fields) **+ M** app-agnostic standard-type classes (one per value type) = N+M classes instead of
N x M. The template supplies the "which app" axis by deliberately *not* proxying storage-related
methods; the standard-type proxy supplies the "what kind of value" axis via everything else that
*is* proxied. Keep this in mind if a future value type or app-specific concern doesn't seem to
fit either side cleanly - the question to ask is which axis it varies along.

**Do not** shortcut this by extending an existing "self-contained stored field" base class in
the app (e.g. `DifferentialStoredCustomField`) just because it already has these three methods
— even though they're identical. Those base classes also define their own *non-proxied*
`getValueForStorage()`/`setValueFromStorage()`/`getValue()`/`setValue()`, designed for fields
that own their value directly. If `{App}ConfiguredCustomField` inherited those, they'd sit
between it and `PhabricatorCustomField` in the MRO and would **silently shadow** the correct
proxy-forwarding versions from `PhabricatorCustomField` (which properly delegate to
`$this->proxy->getValueForStorage()` etc.) — the field would then read/write an unused
property on the template clone instead of the real value on the proxy. Values would appear to
save (no error) but never actually persist or read back. Extend the app's **plain** abstract
custom-field base class directly, and add only the three storage-factory methods above.

If no generic storage classes exist for this app yet (step 1.3 came up empty), create them by
copying the app's own reference pattern if one exists elsewhere in the codebase (e.g. Diffusion's
`PhabricatorRepositoryCustomFieldStorage`/`...StringIndex`/`...NumericIndex` in
`src/applications/repository/storage/`, all trivial subclasses of
`PhabricatorCustomFieldStorage`/`PhabricatorCustomFieldStringIndexStorage`/
`PhabricatorCustomFieldNumericIndexStorage` that just override `getApplicationName()`), then add
a new SQL patch under `resources/sql/patches/` (see Diffusion's own patch history, or
Differential's `resources/sql/patches/20130926.dcustom.sql`, for the three-table shape:
`{app}_customfieldstorage`, `{app}_customfieldstringindex`, `{app}_customfieldnumericindex`),
and register it in `PhabricatorSQLPatchList.php`. Do not hand-edit an existing already-applied
patch.

## 5. Check for other unconditionally-proxied, app-specific hook methods

Some apps' abstract custom-field base classes define extra hooks beyond the generic
`PhabricatorCustomField` set, and forward them to the proxy unconditionally, e.g.
`DifferentialCustomField::shouldAppearInDiffPropertyView()` /
`renderDiffPropertyViewLabel()` / `renderDiffPropertyViewValue()` /
`getWarningsForDetailView()` / `getProTips()`, all doing:

```php
public function someHook() {
  if ($this->getProxy()) {
    return $this->getProxy()->someHook();
  }
  return <default>;
}
```

`PhabricatorStandardCustomField` (the generic, shared-across-apps proxy class) does **not**
implement these app-specific hooks, so calling them on a standard-field proxy throws
`Call to undefined method`. This surfaces at runtime the first time that code path is hit
(e.g. opening the object's detail page), not at write time — so grep for it proactively rather
than waiting to hit each one as a crash:

```bash
grep -n 'getProxy()->' src/applications/{app_dir}/customfield/{App}CustomField.php
```

For every match, override that method on `{App}ConfiguredCustomField` to skip the proxy and
return the same safe default the base class falls back to when there's no proxy at all (usually
`array()`, `false`, or `$this->getFieldName()` — copy it straight from the "no proxy" branch of
the method you're overriding). Fix all of them up front, in one pass — don't wait to hit each
one as a separate crash.

## 6. Rebuild the symbol map

`scripts/symbols/generate_php_symbols.php` requires a live DB connection and may fail in
sandboxed/dev environments with `PhabricatorClusterStrandedException`. If so, use
`../arcanist/bin/arc liberate src` instead — it doesn't need a DB and produces the same
`src/__phutil_library_map__.php` update. Confirm with:

```bash
grep -c "{App}ConfiguredCustomField" src/__phutil_library_map__.php
```

Clean up any stray `._*` AppleDouble junk files this may leave behind on macOS checkouts.

## 7. Verify

1. `php -l` the new/changed files.
2. Set the config, e.g.:
   `bin/config set {app}.custom-field-definitions '{"mycompany.example":{"name":"Example","type":"bool","caption":"An example."}}'`
3. Load the object's detail/edit page and confirm the field appears in the edit form, saves,
   and shows up in the property view / transaction feed (not just the edit form — if it only
   shows in the edit form and nowhere else after saving, that's the storage-not-wired symptom
   from step 4: check `shouldUseStorage()` isn't being shadowed).
4. If you touched Herald/Conduit-relevant hooks, spot check those integrations too.

## Field value storage & lookup reference

Values live in `{app}_customfieldstorage`, keyed by `objectPHID` + `fieldIndex`. The field key
for a config-defined field is `std:{app}:{config-key}` (`PhabricatorStandardCustomField.php:45`);
`fieldIndex` is `PhabricatorHash::digestForIndex($fieldKey)` — first 12 bytes of a raw SHA1,
each byte mapped through the 64-char alphabet `0-9a-zA-Z._` via `ord(byte) & 0x3F`
(`PhabricatorHash.php:45-65`). To compute it ad hoc:

```bash
php -r '
$s = "std:{app}:{config-key}";
$hash = sha1($s, true);
$map = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ._";
$out = "";
for ($i = 0; $i < 12; $i++) { $out .= $map[(ord($hash[$i]) & 0x3F)]; }
echo "$out\n";
'
```
