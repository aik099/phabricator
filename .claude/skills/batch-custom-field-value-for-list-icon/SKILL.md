---
name: batch-custom-field-value-for-list-icon
description: How to cheaply show a single custom field's value (e.g. a boolean, as an icon) on a list view (`PHUIObjectItemListView`, e.g. `PhabricatorAuditListView`, `DifferentialRevisionListView`) for many objects at once, without N+1 queries and without attaching full custom-field lists to every object. Contrasts the lean single-field batched `PhabricatorCustomFieldStorageQuery` pattern against the heavier general `PhabricatorCustomField::getObjectFields()`/`attachCustomFields()` machinery, and covers the `getProxy()` indirection needed if the field happens to be a config-driven "standard" field rather than a plain hardcoded one. Use when a user wants an indicator/icon/column on a list view driven by one specific custom field, or asks how to avoid N+1 queries when reading custom field values for a list of objects. See also: `custom-field-storage-hashing` (the underlying storage-query mechanism), `hardcode-config-driven-custom-field` (a hardcoded field has no proxy indirection, simplifying this pattern further).
---

# Batching One Custom Field's Value Across a List View

## Don't attach a full field list just to read one field for every row

The general pattern — `PhabricatorCustomField::getObjectFields($object, ROLE_VIEW)` +
`attachCustomFields()` — builds **every configured field** for **every object**, one field-object
per (object × configured field) pair, even if the list view only wants to render one specific
field's value as an icon. That's real overhead (object construction, potential per-field setup)
for information the caller will discard immediately, and it's easy to reach for by default because
it's the same call used elsewhere for property views. For a single-field, same-treatment-
everywhere list indicator, instantiate just the one field class per object and batch the storage
read directly:

```php
private function prepareCoAuthoredWithAI() {
  if (!$this->objects) {
    return;
  }
  $viewer = $this->getViewer();
  $target_fields = array();
  foreach ($this->objects as $phid => $object) {
    $target_fields[$phid] = id(new PhabricatorCommitCoAuthoredWithAIField())
      ->setViewer($viewer)
      ->setObject($object);
  }
  id(new PhabricatorCustomFieldStorageQuery())
    ->addFields($target_fields)
    ->execute();
  foreach ($target_fields as $phid => $field) {
    $this->coAuthoredWithAI[$phid] = (bool)$field->getValue();
  }
}
```

## Why this is one query, not N

`PhabricatorCustomFieldStorageQuery::addFields()`/`execute()` groups fields by
`getStorageSourceKey()` (`"{applicationName}/{tableName}"`) and issues **one**
`SELECT ... WHERE objectPHID IN (...) AND fieldIndex IN (...)` per distinct storage source
(`src/infrastructure/customfield/storage/PhabricatorCustomFieldStorage.php:50-88`). Since every
object here reads the same field (same `fieldIndex`, same table), that collapses to a single query
regardless of list length — the batching happens automatically just by constructing all the field
objects before calling `execute()` once, rather than calling a query-and-read helper once per
object in a loop.

This generalizes: if a list view ever needs to batch **two** different single-purpose fields
across the same object set, build both sets of field objects first, then call `addFields()` for
both (or call `addField()`/`addFields()` multiple times on the same query instance) before one
`execute()` — still one query per distinct storage source, not one per field-instance.

## Watch for the proxy indirection on config-driven fields

If the field being batched is a plain hardcoded `PhabricatorCustomField` subclass (see
`hardcode-config-driven-custom-field`), `$field->getValue()` works directly — no indirection.

If instead it's a **config-driven "standard" field** obtained via the generic
`getObjectFields()`/`buildFieldList()` path, the object you get back is the app-specific
multiplexer field (e.g. `PhabricatorCommitConfiguredCustomField`) with the real
`PhabricatorStandardCustomFieldBool`/etc. instance attached as its **proxy** — composition, not
inheritance. Reading the value in that case requires going through the proxy explicitly:

```php
$value = (bool)$field->getProxy()->getFieldValue();  // not $field->getFieldValue()
```

Calling the standard-field-only method (`getFieldValue()`) directly on the outer wrapper throws
`Call to undefined method`, since the wrapper doesn't inherit that API, it merely composes an
object that has it. (See `add-standard-custom-fields`'s "template+proxy split" explanation for why
this composition exists at all.) This distinction disappears entirely once/if the field is
converted to hardcoded — one more reason hardcoding a fixed-meaning field simplifies call sites
like this one.

## Rendering: clone a pre-built icon, don't construct one per row

```php
$ai_icon = id(new PHUIIconView())
  ->setIcon('fa-android indigo')
  ->addSigil('has-tooltip')
  ->setMetadata(array('tip' => pht('Co-authored with AI')));

// ...inside the per-object render loop:
if (idx($this->coAuthoredWithAI, $phid)) {
  $item->addAttribute(clone $ai_icon);
}
```

Building the `PHUIIconView` once outside the loop and cloning it per row avoids redundant
tooltip/sigil setup for every list item.

## Verification

1. Confirm a single query fires for the batched read even with many objects in the list (check via
   query log/profiler, or just read the code path to confirm `execute()` is called once, outside
   any per-object loop).
2. Compare the icon's presence against the actual stored `fieldValue` via direct SQL for a few
   objects — don't rely on visual impression alone, especially while iterating on the field itself
   (see `custom-field-storage-hashing`'s note on fieldIndex mismatches presenting identically to a
   rendering bug).
