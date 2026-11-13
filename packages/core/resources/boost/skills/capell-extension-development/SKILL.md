---
name: capell-extension-development
description: 'Builds a Capell package or extension: scaffolding, the capell.json manifest, provider, migrations, settings, translations, lifecycle actions, tests, and what a Marketplace listing needs. Use when creating, wiring, testing or preparing to publish a Capell package or extension.'
---

# Capell Extension Development

Use this to author a reusable Capell package. For architecture, extension points and Marketplace install operations, use the `capell` skill; this skill does not repeat them. For theme packages, also use `capell-theme-development`.

## First checks

- Read two installed packages under `vendor/capell-app/` that are close to what you are building. Match their provider style, manifest shape, test base and translations.
- Run `php artisan capell:make --list` to see the makers available, then scaffold instead of copying a package directory:

```bash
php artisan capell:make:extension vendor/example --profile=minimal --path=packages --name="Example"
php artisan capell:make:extension vendor/example-tools --profile=full --path=packages --premium
```

`minimal` is a provider, manifest, translations, README, Testbench `TestCase` and manifest test. `full` adds install and admin providers, a settings class and migration, a Layout Builder widget with typed Data objects, widget assets and tests.

## Package shape

- Business operations live in `src/Actions` (one `handle()` method), structured boundaries in `src/Data`, package models in `src/Models`.
- User-facing strings are translated: `resources/lang/en/`, namespaced as `<slug>::<file>.<key>`.
- Declare the bootstrap provider in `composer.json` `extra.laravel.providers` only, and leave the `capell.json` provider buckets empty unless you need an extra gated provider. Listing one class in both places defeats the enabled gate.
- Extend `Capell\Core\Support\Packages\AbstractPackageServiceProvider`. Put work that must stop while the package is uninstalled or disabled in `bootInstalledRuntime()`, and gate optional integrations with `CapellCore::isPackageInstalled('vendor/package')`. `class_exists()` alone does not prove a package is installed.
- Settings and settings migrations, schema extenders and Filament surfaces register through the extension points listed in the `capell` skill.
- Public output must carry no admin or editor state and must come from hydrated data, not Blade queries.

## Manifest (`capell.json`)

Manifest v3 requires `manifest-version`, `name`, `slug`, `displayName`, `kind`, `capellApiVersion`, `version`, `surfaces`, `dependencies` and `providers`, and a `marketplace` object with `summary`, `categories` and `screenshots`. Keep `name` and `version` aligned with `composer.json`.

- `dependencies.requires` lists hard requirements. A package that registers admin pages, resources or admin translations must require `capell-app/admin`.
- `providers` buckets: `metadata`, `install`, `runtime`, `admin`, `frontend`. They do not filter by request context; guard admin-only work in the provider.
- `contributes` declares each shipped surface (for example `model`, `content-widget`, `setting`, `route`, `permission`, `health-check`, `scheduled-job`, `asset`, `migration`). Do not leave it empty when the package ships those surfaces.
- Declare install, setup and after-install work as `actions` classes implementing `Capell\Core\Contracts\PackageLifecycleAction`, not nested Artisan commands. A package with only `commands` cannot be installed from the admin Marketplace.

Run `php artisan capell:package-cache:clear` and `php artisan config:clear` whenever `capell.json` changes. The manifest is cached.

## Load, validate, prove

```bash
composer require vendor/example:@dev
php artisan capell:extension-install vendor/example --dry-run
php artisan capell:extension-install vendor/example
php artisan capell:package:lint packages/example
php artisan capell:extension-audit packages/example
php artisan capell:doctor
```

Use a Composer path repository while developing. Installation does not publish assets or prove rendering: publish assets, add the widget or surface to a development page, and inspect the anonymous response.

## Tests

Use `Capell\Core\Testing\ExtensionTestHarness::forPath(...)` for manifest checks (`assertManifestValid()`, `assertContributionRegistered()`, `assertRoutesOwnedByPackage()`, `assertNoUnsafePublicCache()`). Add tests for provider registration, each Action, settings save and reload, the disabled and enabled states, and escaped public output.

```bash
vendor/bin/pest packages/example/tests
vendor/bin/pint --dirty --format agent
```

## Marketplace listing

Read `references/marketplace-listing.md` before preparing a package for the Marketplace.
