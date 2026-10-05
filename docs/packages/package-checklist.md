# Package Checklist

Use [Extension examples](extension-examples.md) when you need concrete snippets for admin bridges, settings, frontend hooks, Tailwind assets, and cache invalidation.

Use this checklist when creating or reviewing a Capell package.

## Required

- `composer.json` has the package name, PSR-4 namespace, and Laravel provider discovery.
- Dependency constraints admit the latest compatible stable release and retain reasonable older majors. Check used APIs before widening, and update both the aggregate and owning package manifests. After installing repository dependencies, `php scripts/check-composer-major-constraints.php` uses Composer Semver to prove that published manifest constraints and literal workflow/provisioning overrides (including resolved workflow matrix values) admit the exact locked version. It rejects invalid or unresolved constraint expressions and enforces the audited major holds in [`scripts/composer-major-exceptions.json`](../../scripts/composer-major-exceptions.json), which record each dependency, held major, reason and owner. Holds never bypass version admission; a locked-major change or an unused hold requires a new decision. The guard cannot prove registry freshness: the locked version may itself lag the latest stable release, and dependencies absent from the lock have no version benchmark.
- `capell.json` uses manifest v3 and has `manifest-version`, `name`, `slug`, `displayName`, `kind`, `capellApiVersion`, `version`, `surfaces`, `dependencies`, and `providers`.
- New packages start from `php artisan capell:make-extension --profile=minimal` or `--profile=full` unless there is a specific reason to hand-build the scaffold.
- Every PHP file has `declare(strict_types=1);`.
- User-facing strings are translated.
- Domain logic lives in Actions.
- Boundary state uses Data objects.
- Providers are split by runtime context when the package touches multiple surfaces.
- Package metadata comes from `capell.json`; provider-side `CapellCore::registerPackage()` is only for trusted first-party bootstrap or compatibility paths.
- Marketplace/web lifecycle work declares `actions.install`, `actions.setup`, or `actions.afterInstall` classes that implement `PackageLifecycleAction`; matching console commands are only CLI adapters.
- Admin pages/resources/widgets register through `AdminBridge` / `AdminBridgeRegistrar`, or direct `CapellAdmin::contributeToAdminSurface(...)` for small one-off surfaces.
- Settings classes and settings schemas are registered through `SettingsSchemaRegistry`.
- Frontend renderers register stable component keys through `FrontendComponentRegistryInterface`; saved content does not depend on package Blade namespaces.
- Migrations and settings migrations are idempotent.
- Tests cover provider registration, Actions, and any Filament page access.

## Before Release

- Audit dependencies against the package registry for the latest compatible stable releases, including dependencies absent from the lock, and review every audited hold. A passing locked-version guard does not replace this release-time registry audit.
- Run package-focused Pest tests.
- Run generated manifest and public-output safety tests.
- Run the sibling repo test suite.
- Run `composer lint`.
- Run `composer analyze`.
- Confirm package docs and README match the lifecycle Actions and any CLI adapter commands.
- Confirm sibling package integrations use the right manifest relationship: `dependencies.requires` for hard requirements, `dependencies.supports` for support packages that are auto-added when applicable, and `visibility: support` for packages that should not appear as standalone catalogue choices.
- Confirm `class_exists()` is not used as the only availability check for optional Capell packages.
- Confirm frontend requests do not boot admin-only providers.

Activitylog 4 and 5 are supported through `Capell\Core\Support\Activity\ActivityLogCompat` and its `LogOptions`/`LogsActivity` aliases. Models and installer-generated users use this seam. Existing host user models must adopt the Core imports and use `ActivityLogCompat::options()` (or `withoutEmptyLogs()` for custom option chains) before replacing v4. Admin reads `attribute_changes` first and falls back to legacy `properties`. Run the registered Core migration `2026_10_05_000001_add_attribute_changes_to_activity_log_table` in the host before v5 writes logs: it adds the nullable column without moving historical data or dropping `batch_uuid`, so v4 remains supported. Fresh v5 installations use the consolidated vendor migration; the publisher skips the absent v4 event/batch stubs. Republish and review the installed major's `activitylog` config when upgrading: v5 renames retention/soft-delete keys and moves custom table/connection settings to the configured Activity model. The Testbench preparation script copies the installed config from the unchanged vendor path.

The installer automatically adapts a single conventional `App\Models\User` with recognised traits and a literal options chain using methods shared by both majors. Vendor-only empty-log methods, custom hooks or properties, unfamiliar traits, duplicate logging traits, trait aliases/precedence rules and multi-class/namespace files return `Customised` with manual guidance; the file is preserved. Review those shapes manually rather than treating a rewritten options return type as proof that the body runs. In particular, replace `LogOptions::defaults()->dontSubmitEmptyLogs()` with `ActivityLogCompat::withoutEmptyLogs(LogOptions::defaults())`, and read tracked values through `ActivityLogCompat::attributeValues()` rather than the removed `Activity::changes()` method.

Static analysis loads the actual compatibility aliases before symbol discovery. Rector preserves absent-major string names and the custom fixture’s public v4 interface scopes. In temporary dependency overlays, pass the owning source autoloader to Rector and verify reflection paths: a different checkout’s method signatures can make its extra-argument rule remove required arguments.

Compatibility fixtures load the actual installed vendor sources; they do not synthesise a missing major. Run the focused activity coverage in separate dependency environments for 4 and 5. Installer coverage executes each accepted User's options method, persists created/updated activities and queries `activities()` in a subprocess, so a reflection-only check cannot hide a runtime failure.

Icon Picker 5 remains held pending inspection of its field API: Admin subclasses `Guava\IconPicker\Forms\Components\IconPicker` and overrides its protected `setUp()` hook. Version 5 metadata alone cannot establish that this extension remains compatible. OpenSpout 5 also remains held: Filament Actions 5.7.6 and 5.9.0 require `openspout/openspout:^4.23`, so admitting major 5 in a CI override cannot make it resolvable.

## Optional Capell Packages

Composer availability and Capell extension availability are different states. A package class can autoload while the extension is not installed, disabled, or missing its tables. `class_exists()` only proves Composer can load the class; it does not prove `capell:extension-install` and migrations have completed.

Do not gate Capell package queries, models, Blade components, Filament fields, listeners, or Actions with `class_exists()` alone:

```php
if (class_exists(Navigation::class)) {
    Navigation::query()->first();
}
```

Use `CapellCore::isPackageInstalled()` before touching package runtime behavior:

```php
if (CapellCore::isPackageInstalled('capell-app/navigation') && class_exists(Navigation::class)) {
    Navigation::query()->first();
}
```

For optional database-backed integrations, make the installed-state check the first gate. Add a schema check when code can run during install, upgrade, diagnostics, or another partial-migration state:

```php
if (
    CapellCore::isPackageInstalled('capell-app/navigation')
    && Schema::hasTable('navigations')
    && class_exists(Navigation::class)
) {
    Navigation::query()->first();
}
```

`class_exists()` is still fine for non-Capell PHP/library capabilities, dynamic configured classes, autoload priming before cache deserialization, and defensive validation after the Capell package has already been proven installed.
