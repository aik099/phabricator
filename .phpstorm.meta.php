<?php

/**
 * PhpStorm-only IDE metadata. This file is never included at runtime.
 * See https://www.jetbrains.com/help/phpstorm/phpstorm-meta-php.html
 *
 * `id()` (and a few sibling helpers) is defined once in
 * `libphutil/src/utils/utils.php` but used throughout phabricator,
 * libphutil, and arcanist alike, so this same file is duplicated at the
 * root of all three sibling checkouts - PhpStorm's meta-file recognition
 * is per project root, and these three are typically opened as separate
 * projects rather than one shared workspace.
 *
 * These functions are documented as `@param wild` /
 * `@return wild` on purpose (a pre-PHP-5.4 workaround so that
 * `id(new Thing())->doStuff()` could be written where
 * `new Thing()->doStuff()` was a syntax error). Since `wild` isn't a real
 * PHP/PHPDoc type, PhpStorm has nothing to infer from and treats the return
 * value as `mixed`, killing autocomplete for the rest of any chain built on
 * top of it. These `override()` directives tell PhpStorm the actual runtime
 * behavior ("this function returns exactly what you passed in") without
 * touching the functions themselves.
 */

namespace PHPSTORM_META {

  override(\id(0), type(0));
  override(\nonempty(0), type(0));

}
