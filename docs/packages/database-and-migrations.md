# Database And Migrations

Packages own their database tables. Core tables should not grow package-specific columns unless the column is part of a stable extension contract.

## Package Migrations

Place normal migrations in `database/migrations`.

Use Spatie Package Tools from the package provider:

```php
$package
    ->name(self::$name)
    ->hasMigrations([
        'create_example_items_table',
    ]);
```

For dynamic discovery, packages can scan migration filenames and pass them to Package Tools.

## Production Timestamp Defaults

MariaDB 10.5 with `explicit_defaults_for_timestamp=0` gives the first required TIMESTAMP an implicit `ON UPDATE CURRENT_TIMESTAMP` and can reject later required TIMESTAMP columns because of zero defaults. For migrations dated 2026-09-29 onwards, use `dateTime()`, `nullable()`, `useCurrent()` or an explicit `default()`; the Foundation migration guard rejects unspecified required TIMESTAMP columns. Event and expiry values must remain unchanged when unrelated fields are updated.

The forward Core migration `2026_09_29_000001_remove_implicit_timestamp_updates` is self-contained so published copies remain independent of movable package code. It uses Laravel's schema grammar to inspect only columns in Core-created tables and the schema builder to remove automatic updates where MySQL or MariaDB actually added them. The grammar's unprocessed column metadata retains automatic updates that `getColumns()` omits. It preserves nullability, precision, supported insert defaults, comments, indexes and existing values. NULL, CURRENT_TIMESTAMP and literal timestamp defaults are supported; other expressions raise a clear error before altering the column, so they cannot become quoted literals. Other schema grammars and already-safe columns are unchanged, and rollback deliberately does not restore the unsafe behaviour. The optional `RepairsImplicitTimestampUpdates` dialect capability remains available to other callers. Applying the migration cannot reconstruct timestamps that were already rewritten; audit those rows separately against reliable event or expiry records.

The standard Integration regression always uses its own in-memory SQLite connection, independently of the suite's default driver and inherited service environment:

```bash
./capell pest packages/core/tests/Database/ImplicitTimestampRepairTest.php --configuration=phpunit.xml
```

The MariaDB 10.5 proof is declared separately in `phpunit.mariadb.xml` and the standard-suite discovery exceptions. Set `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` to a disposable MariaDB 10.5 server started with `explicit_defaults_for_timestamp=0`, then explicitly name its Laravel connection:

```bash
CAPELL_MARIADB_10_5_CONNECTION=mariadb vendor/bin/pest --configuration=phpunit.mariadb.xml
```

The proof fails if the connection name is absent, the server version differs or `explicit_defaults_for_timestamp` is non-zero. It creates and removes only its own database. `DB_HOST` alone never selects this proof, and no environment skip is used.

## Extension Lifecycle Ledger

Composer presence makes a package available. The `capell_extensions` row controls whether it can affect runtime.

Statuses:

- `installing`: install has started and runtime is inactive.
- `enabled`: runtime providers may load.
- `disabled`: installed but runtime providers must not load.
- `failed`: install failed; runtime remains inactive and error details live in `metadata.install_error`.

Package install actions should mark a package `installing` before running package install commands, `enabled` only after success, and `failed` when an install command throws or exits unsuccessfully.

## Settings Migrations

Place settings migrations in `database/settings` and make them idempotent:

```php
if (! $this->migration-assistant->exists('example.enabled')) {
    $this->migration-assistant->add('example.enabled', true);
}
```

Register settings migrations from the package install command.

## Models

Register package models with Capell when they should appear in package metadata, protected-table checks, exports, or diagnostics:

```php
CapellCore::registerModels([ExampleModel::class]);
```

Use morph maps for polymorphic package models.

## Protected Tables

If a package table should not be removed by cleanup tools, register it:

```php
CapellCore::registerProtectedTable(fn (): string => 'example_items');
```

## Cross-Package Data

Use extension points and Capell installed-state checks instead of hard dependencies when another package is optional.

Do not use `class_exists()` alone to decide whether a Capell package's tables or models are available. Composer can autoload a package class before `capell:extension-install` has marked the package installed or before its migrations have created the tables.

```php
if (CapellCore::isPackageInstalled('capell-app/blog') && class_exists(Article::class)) {
    ExampleRegistry::register(Article::class);
}
```

If code may run during install, upgrade, diagnostics, or other partial database states, also guard the table before querying:

```php
if (
    CapellCore::isPackageInstalled('capell-app/blog')
    && Schema::hasTable('articles')
    && class_exists(Article::class)
) {
    Article::query()->latest()->first();
}
```
