---
name: diffusion-herald-custom-field-actions
description: How Herald "set a custom field's value" actions work for standard (config-driven) custom fields — which field types support it, how to add support to a new type, the native HeraldSelectFieldValue pattern used for fixed-choice fields (Select, Bool) and why the earlier STANDARD_PHID_LIST/tokenizer approach was abandoned (it produced an "Array" description bug and an "Unknown Object (????)" editor bug), the toggle-action pattern used to split a boolean field into separate no-value "Check"/"Uncheck" actions, why changing an action's key/shape orphans already-saved Herald rule rows, a known "select" field UI gotcha (fields default to the first option instead of being empty, and `AphrontFormRadioButtonControl` is the fix), and why a raw-epoch Herald action for "date" fields causes spurious repeated transactions. Use whenever the user asks about a Herald action to set/change a custom field value (on Diffusion commits or any other app), asks why a custom field doesn't show up as a Herald action, asks to add Herald-action support to a new standard custom field type, reports "Array" or "Unknown Object (????)" rendering in a Herald rule's actions, reports that an unset "select" custom field appears/saves as its first option, reports a Herald rule that keeps re-applying/showing a no-op-looking change every reparse, or reports a Herald rule action that suddenly can't be resolved/rendered after a code change. See also: `add-standard-custom-fields`, for adding config-driven custom field support to an application that doesn't have it yet (a different, earlier concern than wiring up Herald actions for fields that already exist).
---

# Herald "Set Custom Field" Actions for Standard Custom Fields

## The mechanism already exists generically — don't write a new HeraldAction

There is **one** reusable `HeraldAction` for this, already wired up for every application:
`src/infrastructure/customfield/herald/PhabricatorCustomFieldHeraldAction.php`
(`ACTIONCONST = 'herald.action.custom'`).

- `HeraldAdapter::getActionImplementationMap()`
  (`src/applications/herald/adapter/HeraldAdapter.php:674`) auto-discovers this action for
  **any** object implementing `PhabricatorCustomFieldInterface` (Diffusion commits already do,
  via `PhabricatorRepositoryCommit`).
- `getActionsForObject()` expands it into one or more concrete action instances per field
  returned by `PhabricatorCustomField::getObjectFields($object,
  PhabricatorCustomField::ROLE_HERALDACTION)` — normally one action per field, but see "Toggle
  actions" below for fields that fan out into several.
- `applyEffect()` applies the value the same way every other Herald "edit" action does: it
  queues a `PhabricatorTransactions::TYPE_CUSTOMFIELD` xaction on the adapter
  (`$adapter->queueTransaction($xaction)`), which later replays through the object's normal
  `PhabricatorApplicationTransactionEditor` — same path as "add auditors", "add comment", etc.

**Never add a new `HeraldAction` subclass, or touch `HeraldCommitAdapter`/
`PhabricatorRepositoryCommit`/any other adapter, to expose "set this field" as a Herald action.**
If a field isn't showing up, the cause is almost certainly the gate below.

## The actual gate: `shouldAppearInHeraldActions()`

A field only appears in the generic action's list if
`PhabricatorCustomField::shouldAppearInHeraldActions()`
(`src/infrastructure/customfield/field/PhabricatorCustomField.php:1586`) returns `true` for it.
It defaults to `false`. This is a **separate** toggle from Herald *conditions*
(`shouldAppearInHerald()`) — a field can be filterable-on without being settable-by-action.

### Status per standard field type (`src/infrastructure/customfield/standard/`)

Config `"type"` strings come from each subclass's `getFieldType()`, matched dynamically by
`PhabricatorStandardCustomField::buildStandardFields()`
(`src/infrastructure/customfield/standard/PhabricatorStandardCustomField.php:25-63`) via a
`PhutilClassMapQuery` — so the full set of valid `"type"` values isn't fixed by any one file,
it's "every `getFieldType()` across every subclass in the codebase":

| `"type"` | class | Herald action support |
|---|---|---|
| `text` | `PhabricatorStandardCustomFieldText` | yes — `STANDARD_TEXT` |
| `bool` | `PhabricatorStandardCustomFieldBool` | yes — **two toggle actions** ("Check X" / "Uncheck X"), no value control at all — see "Toggle actions" below |
| `int` | `PhabricatorStandardCustomFieldInt` | yes — `STANDARD_TEXT` |
| `date` | `PhabricatorStandardCustomFieldDate` | **deliberately not implemented** — see "Why Date support was tried and reverted" below |
| `select` | `PhabricatorStandardCustomFieldSelect` | yes — native `HeraldSelectFieldValue` dropdown over the field's configured `options` — see "The native select pattern" below |
| `remarkup` | `PhabricatorStandardCustomFieldRemarkup` | yes — `STANDARD_REMARKUP` |
| `link` | `PhabricatorStandardCustomFieldLink` | yes — `STANDARD_TEXT` |
| `users` | `PhabricatorStandardCustomFieldUsers` | yes, **for free** — extends `PhabricatorStandardCustomFieldTokenizer`, which already overrides `shouldAppearInHeraldActions()` to `true` |
| `datasource` | `PhabricatorStandardCustomFieldDatasource` | yes, for free — same Tokenizer base |
| `blueprints` | `PhabricatorStandardCustomFieldBlueprints` | yes, for free — same Tokenizer base (Drydock-specific) |
| `header` | `PhabricatorStandardCustomFieldHeader` | **not applicable** — `shouldUseStorage()` is false, there's no value to set |
| `credential` | `PhabricatorStandardCustomFieldCredential` | **not implemented** — would need a new `PhabricatorTypeaheadDatasource` for Passphrase credentials; none exists anywhere in the codebase today (its edit control uses a pre-fetched dropdown, not a searchable datasource) |

Bespoke, hand-written `PhabricatorCustomField` subclasses (non-"standard", one class per field)
already have full control — they inherit the same base-class hooks (see next section) and just
override them directly; no infra changes are ever needed for that side.

## How to add support to a freeform-scalar type that's missing it

For a plain scalar field (text/int/link — anything with no fixed set of choices), it's four
methods, following `PhabricatorStandardCustomFieldText`/`...Int`/`...Link`/`...Remarkup`:

```php
public function shouldAppearInHeraldActions() {
  return true;
}

public function getHeraldActionName() {
  return pht('Set "%s" to', $this->getFieldName());
}

public function getHeraldActionDescription($value) {
  return pht('Set "%s" to: %s.', $this->getFieldName(), $value);
}

public function getHeraldActionEffectDescription($value) {
  return $value;
}

public function getHeraldActionStandardType() {
  return HeraldAction::STANDARD_TEXT; // or STANDARD_REMARKUP for multi-line
}
```

`HeraldAction` only supports four action-value shapes
(`src/applications/herald/action/HeraldAction.php:9-12,58-80`): `STANDARD_NONE`,
`STANDARD_TEXT`, `STANDARD_REMARKUP`, `STANDARD_PHID_LIST`. **Do not reach for
`STANDARD_PHID_LIST` for a fixed/finite set of non-object choices** (a `select` field, a
tri-state flag, etc.) — see the next two sections for the two patterns actually used for that,
and "Why STANDARD_PHID_LIST was tried and abandoned" for what goes wrong if you do.

## The native `HeraldSelectFieldValue` pattern (current implementation for `select`)

For a field with a small, fixed set of choices whose keys aren't real object PHIDs, use
`HeraldSelectFieldValue` (`src/applications/herald/value/HeraldSelectFieldValue.php`) directly —
a plain native `<select>` control (`CONTROL_SELECT`). It takes a literal `key => label` map via
`setOptions()`, stores/reads a plain scalar, and needs no datasource, no PHID resolution, and no
array-wrapping anywhere. This is the same pattern used by a real built-in action outside custom
fields: `PhabricatorFlagAddFlagHeraldAction`
(`src/applications/flag/herald/PhabricatorFlagAddFlagHeraldAction.php`) — its
`getHeraldActionValueType()` returns a `HeraldSelectFieldValue` over `PhabricatorFlagColor`'s
color map, and its `applyEffect()`/`renderActionDescription()` handle a plain scalar throughout.

Wiring (already built generically — a new field type using this pattern needs only the field
hook, nothing else):

- `PhabricatorCustomField::getHeraldActionSelectOptions()` (base hook, default `null`, proxies
  like the other Herald-action hooks near `PhabricatorCustomField.php:1586-1650`) — a field
  returns its `key => label` map here to opt in.
- `PhabricatorCustomFieldHeraldAction::getHeraldActionValueType()` checks this hook first: if
  non-null, builds `id(new HeraldSelectFieldValue())->setKey($field->getFieldKey())
  ->setOptions($options)`; otherwise falls through to `parent::getHeraldActionValueType()` (the
  `STANDARD_*` switch, for `STANDARD_TEXT`/`STANDARD_REMARKUP`/real-PHID-list fields).

`PhabricatorStandardCustomFieldSelect` implementation is just:
```php
public function shouldAppearInHeraldActions() {
  return true;
}

public function getHeraldActionName() {
  return pht('Set "%s" to', $this->getFieldName());
}

public function getHeraldActionDescription($value) {
  $label = idx($this->getOptions(), $value, $value);
  return pht('Set "%s" to: %s.', $this->getFieldName(), $label);
}

public function getHeraldActionEffectDescription($value) {
  return idx($this->getOptions(), $value, $value);
}

public function getHeraldActionSelectOptions() {
  return $this->getOptions();
}
```
No `getHeraldActionStandardType()`, no datasource, no array-unwrap helper, no
`setValueFromApplicationTransactions()` override — all dead weight under this pattern, since the
value arriving at `applyEffect()` is always the plain scalar the native `<select>` submitted, and
the inherited base defaults (`setValueFromStorage($value)` etc.) just work.

## Toggle actions: splitting a boolean field into two no-value actions (current implementation for `bool`)

Rather than exposing Bool as one action with a Checked/Unchecked value picker, it's split into
**two separate actions** — `Check "X"` and `Uncheck "X"` — each with no value control at all,
mirroring how Herald's own **condition** side already treats booleans:
`PhabricatorStandardCustomFieldBool::getHeraldFieldConditions()` exposes
`CONDITION_IS_TRUE`/`CONDITION_IS_FALSE` as two conditions needing no value widget, since the
condition itself fully states the outcome. This is a generic mechanism, not bool-specific — any
field with a small, fixed, enumerable set of "action variants" that need no further input could
use it.

Wiring, all in `PhabricatorCustomFieldHeraldAction`
(`src/infrastructure/customfield/herald/PhabricatorCustomFieldHeraldAction.php`):

- `PhabricatorCustomField::getHeraldActionToggleOptions()` (base hook, default `null`) — a field
  returns `array($value => $actionName, ...)`, one entry per toggle variant, to opt in. The
  **values** double as both the action-picker display names and the fixed value each variant
  implies.
- `getActionsForObject()` checks this hook per field: if non-null, it emits one
  `PhabricatorCustomFieldHeraldAction` instance **per toggle option**, each with
  `setToggleValue($toggle_value)` and a distinct map key `{fieldKey}.{toggleValue}` (e.g.
  `std:diffusion:mycompany.needs-review.1` / `...needs-review.0`) instead of the single
  `{fieldKey}` entry used by every other pattern.
- `isToggleAction()`/`getToggleValue()` accessors gate the rest: `getHeraldActionValueType()`
  returns `new HeraldEmptyFieldValue()` for a toggle instance (no widget); `applyEffect()` uses
  `$this->getToggleValue()` instead of `$effect->getTarget()` as the value to apply;
  `getHeraldActionName()` looks up the toggle value's name in
  `getHeraldActionToggleOptions()`; `renderActionDescription()` substitutes the fixed toggle
  value in place of whatever (irrelevant, empty) target the row happens to have stored, before
  delegating to the field's normal `getHeraldActionDescription($value)`.

`PhabricatorStandardCustomFieldBool`'s relevant surface is just:
```php
public static function getHeraldActionOptions() {
  return array('1' => pht('Checked'), '0' => pht('Unchecked')); // used by effect-log text
}

public function shouldAppearInHeraldActions() {
  return true;
}

public function getHeraldActionToggleOptions() {
  return array(
    '1' => pht('Check "%s"', $this->getFieldName()),
    '0' => pht('Uncheck "%s"', $this->getFieldName()),
  );
}

public function getHeraldActionDescription($value) {
  return $value ? pht('Check "%s".', $this->getFieldName())
                 : pht('Uncheck "%s".', $this->getFieldName());
}

public function getHeraldActionEffectDescription($value) {
  return idx(self::getHeraldActionOptions(), $value, $value);
}
```

## Why `STANDARD_PHID_LIST` was tried and abandoned for Select/Bool

An earlier iteration used `STANDARD_PHID_LIST` (a tokenizer control backed by a datasource) with
fake, non-object "PHIDs" (`PhabricatorTypeaheadResult::setPHID($key)` accepting any opaque string
key) for both Select and Bool. It technically worked, but **produced two real, confirmed-on-a-
live-install bugs**, which is why it was replaced by the patterns above rather than just
patched:

1. **"Array" in the read-only rule description.** `HeraldAdapter::renderActionAsText()`
   (`HeraldAdapter.php:1028`) passes the raw stored `$action->getTarget()` into
   `renderActionDescription()`. For `STANDARD_PHID_LIST`, `willSaveActionValue()`
   (`HeraldAction.php:90`) always stores `array_keys($value)` — an **array**, never a scalar.
   Field-side description methods that assumed a scalar (`idx($options, $value, $value)`)
   silently got an array, and PHP string-cast it to the literal word `"Array"`. (Also caught:
   `if ($value)` truthiness checks on a non-empty array are always `true` regardless of content —
   `["0"]` is still truthy — so a naive Bool "Check"/"Uncheck" branch on the raw target would
   never render "Uncheck" correctly either.)
2. **"Unknown Object (????)" in the rule editor**, confirmed via the token's actual submitted
   HTML (`<input type="hidden" value="rejected">` next to a rendered label of "Unknown Object
   (????)" — the *value* was right, only the *display* was wrong). Root cause:
   `HeraldTokenizerFieldValue::renderEditorValue()` (used to hydrate a saved action/condition's
   *current* value in the edit form) ignores `setValueMap()` entirely and always calls
   `$datasource->getWireTokens($values)`, which resolves each key through real
   object/PHID-handle lookups — which fail for synthetic, non-PHID keys. The fix (still in place
   today, since the pre-existing Herald **condition** side for Select uses the same datasource)
   is overriding `renderSpecialTokens()` on the datasource (the established extension point used
   by e.g. `ManiphestTaskStatusDatasource` for the same "fixed choices, not real objects"
   scenario) to serve labels for those keys directly, bypassing the real-object lookup path. See
   `PhabricatorStandardSelectCustomFieldDatasource::renderSpecialTokens()`/`buildResults()`.

Both bugs stemmed from the same root mismatch: `STANDARD_PHID_LIST` is built and tested
throughout Herald/typeahead machinery on the assumption of **real object PHIDs**; a fixed,
finite, non-object choice set is a fundamentally different shape that happens to be *coercible*
into that machinery via a fake datasource, but every part of the machinery that assumes "real
object" (array-of-keys storage, handle-based label resolution) has to be specifically worked
around. `HeraldSelectFieldValue` and the toggle-action pattern above have no such mismatch, so
prefer them for any future fixed-choice, non-object field — don't reach for the
`STANDARD_PHID_LIST`-plus-fake-datasource route again.

(One related datasource note that's still useful even outside this abandoned path: if you ever
do have real PHID-list actions with a small, fixed vocabulary the admin should be able to browse,
`renderSpecialTokens()` is the correct extension point, not `loadResults()` alone.)

## Changing an action's key or value-shape orphans already-saved Herald rule rows

Learned twice in the same session — once with `date` (added, then reverted for a different
reason), once with the Bool toggle-split (which **changes the action's map key** from
`{fieldKey}` to `{fieldKey}.{toggleValue}`): **any** change to how
`PhabricatorCustomFieldHeraldAction::getActionsForObject()` derives a field's map key(s), or a
change to what an action's `getHeraldActionValueType()` returns while an existing rule still
holds the *old* value shape in storage, breaks any already-saved rule that references the old
identity. `HeraldAdapter::requireActionImplementation()`/the rule editor can't resolve the
missing key, and re-opening the rule fails to render that action row.

This is not something the harness/database can migrate automatically — there's no versioning on
saved action keys. When iterating on an action's shape during development against a real
install with existing test rules:
- Expect to manually remove and re-add the affected action row in every rule that references it,
  every time the shape changes (not just once at the end).
- If you need to keep working on other things live while doing this, it's reasonable to
  temporarily revert to the previous shape, let the human fix up their rule, then reapply — this
  was done twice in this session (see conversation history) and worked cleanly since the revert
  target was known-good, exactly-reproducible code, not a guess.
- There is no way to add a "select" or "toggle" pattern to a field **without** changing its
  action identity if it previously used a different pattern (e.g. `STANDARD_PHID_LIST`) — the
  value shape and/or key scheme necessarily differs. Warn the human up front, before making the
  change, that this will happen — don't let them discover it as a surprise "crash."

## Why Date support was tried and reverted

`PhabricatorStandardCustomFieldDate` briefly had `STANDARD_TEXT` Herald-action support (raw
epoch integer, since there's no dedicated date-picker Herald action value type) but it was
**removed** after live testing on a real install showed the rule re-applying — and showing up in
the transcript — on every single reparse, even when nothing meaningful had changed. Root cause,
confirmed by testing:

- `PhabricatorCustomFieldHeraldAction::applyEffect()` compares `$old_value`/`$new_value` as
  **raw epoch integers** with strict `!==`
  (`PhabricatorCustomField::getApplicationTransactionHasEffect()` default,
  `PhabricatorCustomField.php:914-920`).
- The commit's actual stored value (set via the normal edit form's `AphrontFormDateControl`
  date/time picker) and the epoch literally typed into the Herald rule's raw-text action value
  can differ by a few seconds without any way to notice, because `phabricator_datetime()` (used
  in both the transaction feed and property view) **doesn't render seconds** — two genuinely
  different epochs display as the identical string (e.g. "May 22 2025, 01:32" for both old and
  new), making a real, repeatedly-reapplied diff look like a no-op bug.
- There's no practical way for an admin to type a byte-exact epoch into a Herald rule's plain
  text field that's guaranteed to match whatever second-precision value the date picker produced
  — so every reparse looks like it "changes" a date field that, from the UI's perspective, never
  changed.

General lesson for any future numeric/time field Herald-action work: if a field's rendering
truncates precision that its storage doesn't (dates without seconds, floats rounded for display,
etc.), a `STANDARD_TEXT` raw-value action is fragile — the has-effect check operates on the full-
precision stored value, but a human configuring the action can only reasonably provide the
truncated, display-precision value. Either don't add action support for such a field until there's
a UI that produces exactly the same precision as storage, or add a field-specific
`getApplicationTransactionHasEffect()` override that treats "no visible change" (e.g. same minute)
as no effect — the latter wasn't attempted here since there was no concrete need for editing dates
via Herald in the first place.

## Known gotcha: unset `select` fields render/save as their first option

This is a pre-existing quirk of the field type's own **edit form** control, unrelated to Herald
— it affects e.g. `DiffusionCommitEditEngine` for commits, not the Herald action UI (which uses
`HeraldSelectFieldValue`/a native `<select>` too, but see below — not yet fixed there either).

`PhabricatorStandardCustomFieldSelect::renderEditControl()` renders a plain native HTML
`<select>` (`AphrontFormSelectControl`) built only from the configured `options` map. If the
stored value is empty and no option key matches it, `AphrontFormSelectControl::renderOptions()`
(`src/view/form/control/AphrontFormSelectControl.php:75-90`) marks **no** `<option>` as
`selected` — which means the browser (not Phabricator) defaults to displaying, and will submit
if the form is saved, whichever option comes **first** in the markup. This can silently set the
field to that first value if someone edits the object and saves without touching the dropdown.

Two fix options, neither applied yet:
1. Add an explicit blank option as the first key in the `options` config, e.g.
   `"options": {"": "(None)", "pending": "Pending", ...}` — `renderPropertyViewValue()` already
   special-cases empty string as "show nothing", so this makes the field read as unset
   everywhere once the blank option exists. Requires remembering to do it per-field in config.
2. **Better, not-yet-applied fix**: swap `renderEditControl()` to build an
   `AphrontFormRadioButtonControl` (`src/view/form/control/AphrontFormRadioButtonControl.php`)
   instead of `AphrontFormSelectControl`, following the precedent in
   `PonderQuestionStatusController` (and Calendar/People/Flag/Drydock/Dashboard edit
   controllers). Its `renderInput()` only marks a button `checked` when `$button['value'] ==
   $this->getValue()` — if the value matches nothing, **no** button is checked, and (unlike a
   `<select>`) an unchecked HTML radio group has no "auto-select the first one" browser
   behavior, and submits no value at all for that field name if none is checked. This fixes the
   bug at the control level for every Select field, with no config changes required. Trade-off:
   radio buttons render as a vertical list, a better fit for a handful of options than a long
   list (for many options, `AphrontFormTypeaheadControl` — a genuine single-value searchable
   typeahead, as opposed to the multi-token `AphrontFormTokenizerControl` — is the more relevant
   control to reach for instead).

The Herald **action** picker for select fields uses `HeraldSelectFieldValue`
(`CONTROL_SELECT`) too — this same "no selection = defaults to first item" browser behavior
likely applies there as well; it hasn't been specifically exercised/confirmed, so check before
assuming it's fine.

## Reference: config JSON covering every type, for manual testing

```json
{
  "mycompany.approval-status": {
    "name": "Approval Status", "type": "select",
    "options": { "": "(None)", "pending": "Pending", "approved": "Approved", "rejected": "Rejected" }
  },
  "mycompany.needs-review": { "name": "Needs Review", "type": "bool" },
  "mycompany.notes": { "name": "Notes", "type": "text" },
  "mycompany.risk-score": { "name": "Risk Score", "type": "int" },
  "mycompany.deploy-date": { "name": "Deploy Date", "type": "date" },
  "mycompany.description": { "name": "Extended Description", "type": "remarkup" },
  "mycompany.ticket-link": { "name": "Ticket Link", "type": "link" },
  "mycompany.watchers": { "name": "Watchers", "type": "users" },
  "mycompany.related-projects": {
    "name": "Related Projects", "type": "datasource",
    "datasource.class": "PhabricatorProjectDatasource"
  },
  "mycompany.info": { "name": "Reference Information", "type": "header" }
}
```
Set via the admin config page (`/config/edit/{app}.custom-field-definitions/`) or
`bin/config set {app}.custom-field-definitions '...'` (writes to the DB-backed config source in
a full install; `bin/config` needs a live DB connection, which isn't available in every sandbox).

## Verification checklist

1. `php -l` every changed file; `arc lint` the changed paths.
2. Regenerate the symbol map if any class was added/removed (`scripts/symbols/generate_php_symbols.php`
   needs a live DB — use `arc liberate <dir>` instead if that's unavailable; both leave stray
   macOS `._*` AppleDouble files on `/Volumes`-style checkouts worth cleaning up).
3. Create a global Herald rule on the relevant content type (e.g. Diffusion "Commits") and
   confirm each field now appears in the action dropdown with the expected control (text box /
   native select / two toggle actions with no control).
4. Use Herald's rule-editor "Test Rule" feature (`HeraldCommitAdapter::newTestAdapter()` for
   commits) to trigger the rule against a real object and confirm the `TYPE_CUSTOMFIELD`
   transaction actually applies, both in the transcript and in the rule's own read-only
   description text (check specifically for literal "Array" or "Unknown Object" — those don't
   throw, they just render wrong).
5. If a rule already has an action for a field whose shape you're changing, expect it to break
   (see "Changing an action's key or value-shape" above) — don't be surprised by it.

Confirmed working end-to-end on a real install (text, bool, int, remarkup, link, select,
users/tokenizer, datasource all applied cleanly via Herald, each recorded as a distinct
`Herald changed/set/checked/updated ...` transcript entry with a working "View Herald
Transcript" link; select correctly produced **no** transaction when the target value already
matched the stored value, matching normal xaction has-effect behavior). The native
`HeraldSelectFieldValue`/toggle-action versions of Select/Bool were implemented to fix real bugs
found in the earlier `STANDARD_PHID_LIST` version, but re-verifying them end-to-end on the live
install (description text, editor hydration, applied effect) after the final revert-and-reapply
round trip is still outstanding. `date` remains the one field deliberately left unsupported —
see "Why Date support was tried and reverted" above.
