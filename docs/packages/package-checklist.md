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

Activitylog 5 needs a code and schema migration before admission: Core models and the installer still use `Spatie\Activitylog\LogOptions`, `Spatie\Activitylog\Traits\LogsActivity` and `dontSubmitEmptyLogs()`, while Admin reads tracked changes from `properties`. Version 5 moves those classes, renames that method and stores tracked changes in `attribute_changes`.

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
