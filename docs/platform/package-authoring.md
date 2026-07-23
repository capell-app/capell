# Package Authoring

Capell treats package authoring as a platform surface, not a side effect of Laravel auto-discovery. A package should declare what it owns in `capell.json`, route runtime work through provider buckets, and keep public output safe for anonymous users and cached HTML.

For the complete third-party path from scaffold through a rendering proof and
Marketplace submission, read [Extension and theme development](../../packages/core/docs/extension-development.md).

## Scaffold First

Use the existing `capell:make-extension` command to create new packages:

```bash
php artisan capell:make-extension
php artisan capell:make-extension vendor/example --profile=minimal --path=packages --name="Example"
php artisan capell:make-extension vendor/example-tools --profile=full --path=packages --premium
```

Interactive mode asks for the package name, scaffold profile, target directory, and display name when they are missing. Non-interactive scripts must pass `package`, `--profile`, and `--path`.

## Profiles

`minimal` creates the smallest useful package:

- Composer metadata and PSR-4 autoloading.
- Manifest v3 with all provider buckets present and only the runtime bucket populated.
- A runtime provider extending `AbstractPackageServiceProvider`.
- Translations, README, and a manifest/public-cache safety test.

`full` creates live, harmless examples:

- Metadata, install, runtime, and admin providers.
- A package-owned console command.
- Settings registration with translated labels.
- A working Layout Builder content widget with typed input and render Data objects.
- Package-owned widget CSS and JavaScript assets.
- Tests for manifest validity and provider bucket shape.

## Safety Rules

Public package output must not expose admin or editor details. Widgets, render hooks, and Blade views should receive hydrated public state from Actions or view models, not query models directly. Cached HTML must remain safe to serve to anonymous visitors, signed-in users, admins, crawlers, and static exports.

Use the generated tests as the baseline, then add package-specific tests for settings, commands, admin surfaces, routes, migrations, and frontend rendering before release.

## Lifecycle Actions

Install, setup, and after-install work that can run from the web UI or Marketplace must be implemented as Actions, not nested Artisan commands. Add lifecycle classes that implement `Capell\Core\Contracts\PackageLifecycleAction` and declare them in `capell.json`:

```json
{
    "actions": {
        "install": "Vendor\\Example\\Actions\\InstallExamplePackageAction",
        "setup": "Vendor\\Example\\Actions\\SetupExamplePackageAction",
        "afterInstall": "Vendor\\Example\\Actions\\AfterInstallExamplePackageAction"
    }
}
```

Console commands may remain as thin CLI adapters for developers, but they should delegate to the same Action. Web-triggered lifecycle work will prefer these Actions and will not fall back to legacy commands.

## Install In An App

Require published packages normally:

```bash
composer require vendor/example
php artisan optimize:clear
php artisan capell:package-cache:clear
```

For local development, point the app at the package directory you are actively
working on. A consuming app can keep local extensions inside its own
`packages/` directory:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/*",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then require the package by Composer name:

```bash
composer require vendor/example:@dev
```

Use the wildcard path repository when one app should load several local packages. For one package only, point Composer at that package path:

```bash
composer config repositories.vendor-example path packages/example
composer require vendor/example:@dev
```

## Share On Marketplace

Before sharing a package, tag a release, run package tests, and audit the manifest:

```bash
php artisan capell:extension-audit packages/example
```

Marketplace-ready packages should have aligned `composer.json` and `capell.json` names, clear `marketplace` metadata, screenshots where useful, support notes in the README, and a normal Composer install path through Packagist, private Composer, VCS, or another configured repository.

## Next

- [Packages](../packages/README.md)
- [Package anatomy](../packages/package-anatomy.md)
- [Package checklist](../packages/package-checklist.md)
- [Extension point chooser](../packages/extension-point-chooser.md)
- [Build an extension end to end](../packages/build-extension-end-to-end.md)
