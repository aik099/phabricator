---
name: phpstorm-meta-autocomplete
description: Why libphutil helpers like id() and nonempty() defeat PhpStorm/IDE autocomplete on fluent chains (e.g. id(new Foo())->bar()->baz()), and the .phpstorm.meta.php fix already in place at the root of all three sibling checkouts (phabricator, libphutil, arcanist). Use when the user reports missing/broken autocomplete or type inference after id(...)/nonempty(...) calls, asks why chained method calls on these helpers don't autocomplete, or wants to extend IDE hinting to another libphutil helper with the same "returns one of its untyped arguments" shape.
---

# Restoring PhpStorm Autocomplete Through `id()`/`nonempty()` Chains

## The root cause

`id($x) { return $x; }` (`libphutil/src/utils/utils.php:19-21`) exists purely as a pre-PHP-5.4
syntax workaround: `new Thing()->doStuff()` was a parse error before PHP 5.4 legalized directly
dereferencing a `new` expression, so `id(new Thing())->doStuff()` was the one-line fix to keep
fluent construction chains fluent. Its docblock documents this explicitly and types both the
parameter and return as `wild` — libphutil's own convention word for "no type info, could be
anything" (the same word used for the `wild` `PhabricatorApplicationConfigOptions` config type).
`wild` is not a real PHP/PHPDoc type, so PhpStorm (or any IDE) has nothing to infer from and
treats the return as `mixed` — every method call chained after `id(new Foo())` loses static
analysis and autocomplete from that point on. `nonempty()` (`utils.php:696-704`, variadic,
returns the first truthy arg or the last one) has the identical problem.

This is purely a static-analysis/tooling gap — it has zero effect on runtime behavior, and the
underlying PHP-grammar limitation `id()` was built for has been obsolete since PHP 5.4 (over a
decade); `id(...)` just remains the established house style across thousands of existing call
sites, so it's not going away.

## The fix already in place

A `.phpstorm.meta.php` file (PhpStorm-only IDE metadata, never included at runtime — see
https://www.jetbrains.com/help/phpstorm/phpstorm-meta-php.html) exists at the **root of all
three sibling checkouts** — `phabricator/.phpstorm.meta.php`, `libphutil/.phpstorm.meta.php`,
`arcanist/.phpstorm.meta.php` (identical content in each). It's duplicated three times
deliberately: `id()`/`nonempty()` are defined once in `libphutil`, but PhpStorm's meta-file
recognition is per **project root**, and these three sibling directories are typically opened as
separate PhpStorm projects rather than one shared multi-root workspace — a single copy in
`libphutil` alone would not help autocomplete while working inside `phabricator` or `arcanist`.

```php
namespace PHPSTORM_META {
  override(\id(0), type(0));
  override(\nonempty(0), type(0));
}
```

`override(\id(0), type(0))` tells PhpStorm "the return type of `id()` equals the type of its
0th argument" — this is exactly what's needed to make `id(new Foo())->bar()` autocomplete `bar()`
against `Foo`, without touching `id()`'s actual implementation or docblock at all.

## The lint gotcha this file trips

`arc lint`/`.arclint`'s PHP-compatibility linter targets PHP 5.2.3 (this codebase's actual
minimum supported version) and errors on the `namespace PHPSTORM_META { ... }` block PhpStorm's
meta-file format requires (`namespace` wasn't introduced until PHP 5.3) — `XHP45 PHP
Compatibility`. This is a false positive for this specific file: it's never loaded/executed by
the runtime, so its own PHP-version syntax is irrelevant. Fix, an added `.arclint` exclude entry:

```json
"exclude": [
  "(^externals/)",
  ...,
  "(^\\.phpstorm\\.meta\\.php$)"
]
```

This exclusion belongs in all three repos' `.arclint` (`phabricator`, `libphutil`, `arcanist`) —
confirmed present in all three as of last check. If a fresh check ever finds one of them missing
it, don't assume that's deliberate: it's just as likely mid-flight git state (a stash, an
in-progress branch, an uncommitted edit not yet applied) as an intentional removal. Check
`git status`/`git stash list` for that repo before concluding anything about intent, and ask
rather than guess if it's ambiguous.

Also watch for the ASCII-only-source lint rule (`TXT5 Bad Charset`) if editing this file's
docblock comments — Phabricator's style linter rejects em-dashes and other non-ASCII bytes in any
`.php` file, including this excluded-from-compat-checking one (the charset check isn't part of
the exclusion).

## Extending this to other helpers

Any libphutil function with the same "returns exactly one of its arguments, untyped" shape is a
candidate for the same `override(\func(N), type(N))` treatment. Confirmed same-shaped helpers in
`libphutil/src/utils/utils.php`: `nonempty()` (already added, `type(0)` is an approximation since
it's variadic and could return any argument — still far better than `mixed`). `head($arr)`/
`last($arr)` (`utils.php:761-775`) are a different shape — they return an *element* of an array
argument, not the array itself — PhpStorm's `elementType(0)` directive (not `type(0)`) is the
correct one for those, and only works well if the array's PHPDoc is annotated with a concrete
element type (e.g. `Foo[]`) at the call site, which most Phabricator code doesn't do consistently
— lower value to add than `id()`/`nonempty()`, not currently implemented.

## Verification

- `php -l .phpstorm.meta.php` in each of the three repos.
- `arc lint .phpstorm.meta.php` should report "No paths are lintable" in all three repos when
  each one's `.arclint` exclude is in place. If one instead reports `XHP45 PHP Compatibility`,
  check that repo's git state (see above) before treating it as something to fix.
- In PhpStorm itself: open a file with `id(new SomeKnownClass())->`, confirm method autocomplete
  now offers `SomeKnownClass`'s methods instead of nothing/generic `mixed` suggestions. (Requires
  the IDE to re-index after the meta file is added — reopening the project is the reliable way.)
