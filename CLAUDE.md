# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Phabricator is a large PHP web application suite (code review, bug tracking, wikis, project
management, repository hosting, etc). It depends on two sibling projects that are normally
checked out next to it: `libphutil` (core PHP utility/class-map library) and `arcanist` (CLI
tooling, used for linting and running unit tests). Expect `../libphutil` and `../arcanist` to
exist as siblings of this `phabricator` checkout.

## Commands

- **Run unit tests**: `arc unit --everything` (from arcanist), or target a specific test class
  with `arc unit <path/to/file.php>`. Test classes extend `PhabricatorTestCase` and live in
  `__tests__/` directories alongside the code they test (see `.arcunit`, which wires the
  `phutil` unit engine to any `*.php` file).
- **Lint**: `arc lint` (config in `.arclint`).
- **Storage/schema management**: `bin/storage upgrade` applies DB schema patches (actual `.sql`
  patch files live in `resources/sql/patches/`, registered in
  `src/infrastructure/storage/patch/PhabricatorSQLPatchList.php`); `bin/storage status` shows
  pending patches. Never hand-edit an already-applied patch — add a new one instead.
- **Rebuild the class/symbol map** after adding/removing/renaming a class or moving files:
  `scripts/symbols/generate_php_symbols.php` (or `arc liberate` from the sibling `libphutil`/
  `arcanist` checkouts). Autoloading depends on `src/__phutil_library_map__.php`, which is
  generated, not hand-maintained — stale maps cause "class not found" errors.
- **Other `bin/` entry points** worth knowing about: `bin/config` (site config), `bin/celerity`
  (rebuild the CSS/JS resource map after editing static resources), `bin/cache` (purge caches),
  `bin/user`, `bin/repository`, `bin/worker` (daemons/task queue), `bin/mail`.
- Daemons (task queue workers, repository pulling, etc.) are managed via `bin/phd` in a full
  install; not generally needed for isolated code changes.

## Architecture

### Library loading
PHP files are not `require`'d directly. `src/__phutil_library_map__.php` maps every class/function
to its file, and libphutil's autoloader resolves symbols through it at runtime. **Any time you
add, remove, or rename a class or move a file, the map is stale until it's regenerated** (see
Commands above).

### The "application" pattern
Each product feature (`src/applications/<name>/`) follows a consistent Model-View-Controller-ish
layout — see `src/applications/maniphest/` as a reference example:
- `application/` — the `PhabricatorApplication` subclass that registers the app, its URI, icon,
  and capabilities in the app launcher.
- `controller/` — `AphrontController` subclasses handling HTTP requests/routes.
- `storage/` — `LiskDAO` subclasses (Phabricator's lightweight ORM) representing DB-backed
  objects, plus a `*SchemaSpec.php` describing the table schema.
- `query/` — `PhabricatorCursorPagedPolicyAwareQuery` subclasses for loading objects with policy
  (permission) filtering and pagination baked in.
- `editor/` and `xaction/` — transaction-based mutation layer: edits to an object go through an
  `Editor` that applies a list of `Transaction` ("xaction") objects, which is how activity feeds,
  Herald rules, notifications, and audit history all get generated for free.
- `phid/` — PHID type definitions so objects can be referenced/handled generically across apps.
- `conduit/` — API method implementations exposed over Conduit (Phabricator's RPC API).
- `capability/`, `policy/`, `policyrule/` — fine-grained permission definitions for the app.
- `field/` and `search/` — custom fields and search/index integration.
- `herald/` — hooks into Herald (the rules-engine for notifications/automation).
- `__tests__/` — unit tests for the application.

New features should generally follow this same directory layout rather than introducing new
patterns, since a lot of shared infrastructure (policy checks, transactions, PHIDs, search
indexing) expects objects to be assembled this way.

### Storage layer
`LiskDAO` (see `src/infrastructure/storage/lisk/`) is the base ORM class. Schema is declared in
code (`*SchemaSpec.php` per application) and reconciled against the live DB via SQL patch files in
`resources/sql/patches/`, registered in
`src/infrastructure/storage/patch/PhabricatorSQLPatchList.php` and applied with
`bin/storage upgrade`. Do not modify old patch files; add new ones for schema changes.

### Static resources
CSS/JS live under `webroot/rsrc/` and are managed by Celerity (`src/infrastructure/celerity`),
which maps symbolic resource names to built files. After editing/adding static resources, run
`bin/celerity map`.

### Config
Site configuration is defined by `PhabricatorApplicationConfigOptions` subclasses scattered
across applications and read via `PhabricatorEnv::getEnvConfig()`. Local overrides live under
`conf/` (`conf/local/local.json` for local installs).
