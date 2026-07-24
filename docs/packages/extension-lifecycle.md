# Extension Lifecycle

Use this when a package should be discovered, audited, installed, enabled, and surfaced by Capell without patching host package code.

The lifecycle has four parts:

1. Composer makes the package classes available.
2. `capell.json` describes the extension contract.
3. Install providers and commands prepare database/config state.
4. Runtime/admin/frontend providers load only when the extension is enabled.

Uninstalling an extension also removes any published schema migration files that match files in the extension's `database/migrations` directory. To run that cleanup directly, use `php artisan capell:delete-migrations vendor/example`, or `php artisan capell:delete-migrations --all` for every registered Capell package.

## Minimum Files

```text
packages/example
├── composer.json
├── capell.json
├── src/Providers/ExampleInstallServiceProvider.php
├── src/Providers/ExampleServiceProvider.php
└── tests
```

Add admin, frontend, migrations, settings, translations, and docs only when the package needs them.

## Composer Metadata

Composer discovery should make provider classes available. Capell decides when each provider bucket is loaded.

```json
{
    "name": "vendor/example",
    "type": "library",
    "autoload": {
        "psr-4": {
            "Vendor\\Example\\": "src"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Vendor\\Example\\Providers\\ExampleMetadataServiceProvider"
            ]
        }
    }
}
```

Keep the Composer-discovered provider safe. It can publish metadata, but it should not register runtime behaviour that depends on the extension being enabled.

## Manifest Shape

`capell.json` uses manifest version 3. The validator rejects v1/v2 fields such as `capell-version`.

```json
{
    "manifest-version": 3,
    "name": "vendor/example",
    "slug": "example",
    "displayName": "Example",
    "kind": "package",
    "visibility": "catalogue",
    "capellApiVersion": "^1.0",
    "version": "1.0.0",
    "description": "Example extension for Capell.",
    "product": {
        "group": "Capell Foundation",
        "tier": "free"
    },
    "namespace": "Vendor\\Example\\",
    "surfaces": ["admin", "frontend", "console"],
    "dependencies": {
        "requires": ["capell-app/core"],
        "supports": ["capell-app/admin", "capell-app/frontend"],
        "conflicts": []
    },
    "providers": {
        "metadata": [
            "Vendor\\Example\\Providers\\ExampleMetadataServiceProvider"
        ],
        "install": [
            "Vendor\\Example\\Providers\\ExampleInstallServiceProvider"
        ],
        "runtime": ["Vendor\\Example\\Providers\\ExampleServiceProvider"],
        "admin": ["Vendor\\Example\\Providers\\ExampleAdminServiceProvider"],
        "frontend": [
            "Vendor\\Example\\Providers\\ExampleFrontendServiceProvider"
        ]
    },
    "contributes": [],
    "contributionTraceability": [],
    "database": {
        "migrations": true,
        "settings": false,
        "requiredTables": []
    },
    "commands": {
        "install": "capell:example-install",
        "setup": null,
        "setupParams": [],
        "demo": null,
        "demoParams": [],
        "health": null
    },
    "settings": [],
    "permissions": [],
    "capabilities": [],
    "performance": {
        "cacheTags": [],
        "cacheSafety": {
            "cacheable": false,
            "sensitiveOutput": false,
            "queueInvalidation": false,
            "variesBy": [],
            "invalidationSources": []
        }
    },
    "healthChecks": [],
    "commercial": {
        "privateDocsRequested": false
    },
    "marketplace": {
        "summary": "Adds an example extension surface.",
        "categories": ["example"],
        "screenshots": []
    }
}
```

`visibility` defaults to `catalogue`. Set it to `support` for dependency-only packages such as theme admin/core helpers. Support packages are hidden from normal package selection, but they remain installable when a visible package lists them in `dependencies.supports`.

Provider buckets must all exist, even when some lists are empty. Provider classes must be inside the package PSR-4 namespace and must extend Laravel's `ServiceProvider`.

## Provider Buckets

| Bucket     | Loads when                                               | Allowed work                                                                             |
| ---------- | -------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `metadata` | The package is discovered                                | Safe metadata only. Do not register runtime routes, resources, views, or listeners here. |
| `install`  | Installer or extension install workflow runs             | Install commands, migration publishing, setup helpers, one-off checks.                   |
| `runtime`  | Extension is enabled                                     | Shared runtime services, models, policies, events, package config.                       |
| `admin`    | Extension is enabled and admin/console runtime is active | Filament resources, admin bridges, widgets, settings schemas, admin routes.              |
| `frontend` | Extension is enabled and frontend runtime is active      | Render hooks, frontend components, frontend routes, Tailwind assets, cache dependencies. |

The separation matters. Admin code should not load on public frontend requests, and install-only wiring should not stay active after setup.

## Contributions

Use `contributes` when a package exposes contract-backed capabilities that Capell can audit.

```json
{
    "type": "admin-resource",
    "class": "Vendor\\Example\\Extensions\\ExampleAdminResourceContribution"
}
```

Each contribution class must implement the contract expected by its `type`. Current contribution types include:

- `admin-resource`
- `admin-page`
- `admin-action-extender`
- `section`
- `page-type`
- `page-variation`
- `dashboard-widget`
- `overview-stat`
- `schema-extender`
- `configurator`
- `model`
- `permission`
- `route`
- `setting`
- `frontend-component`
- `render-hook`
- `asset`
- `migration`
- `scheduled-job`
- `console-command`
- `agent-capability`
- `health-check`
- `workflow-attention`

Use existing package extension points directly when the package only needs a small local hook. Use manifest contributions when the package should be auditable by Capell tooling.

## Audit Before Install

Run the audit before wiring a package into an app:

```bash
php artisan capell:extension-audit packages/example
php artisan capell:extension-audit packages/example/capell.json
```

The audit checks the manifest shape, provider buckets, class namespaces, contribution contracts, cache-safety metadata, health checks, and Marketplace metadata.

## Install Flow

Once Composer has installed the package:

```bash
php artisan capell:package-cache:clear
php artisan capell:package-cache
php artisan capell:extension-install vendor/example --dry-run
php artisan capell:extension-install vendor/example
```

Use `--dry-run` first on a new package. Use package-scoped params when the extension declares install parameters:

```bash
php artisan capell:extension-install vendor/example \
    --url=https://example.test \
    --languages=en \
    --sites=Main \
    --param=vendor/example:seedDemo=true
```

## Uninstall Flow

Uninstall keeps extension-owned data by default so the package can be reinstalled later with its previous state intact:

```bash
php artisan capell:extension-uninstall vendor/example --dry-run
php artisan capell:extension-uninstall vendor/example
```

If the admin intentionally wants to remove extension-owned data, pass `--delete-data`. If the Composer package should also be removed, pass `--delete-package`; this deletes extension-owned data before running Composer removal.

Packages that own tables or other persistent state can implement `Capell\Core\Contracts\Extensions\DeletesExtensionData` on their service provider or a manifest provider class. Capell calls `deleteExtensionData(PackageData $package)` only when the admin chooses data deletion or deletes the package.

## Runtime Rules

- Optional packages must not register runtime providers until enabled.
- Package writes belong in Actions, not resource classes or controllers.
- User-facing strings belong in package translations.
- Public frontend output must not include authoring markers, model IDs, field paths, package internals, permissions, or signed admin URLs.
- Cacheable frontend output needs invalidation metadata in the manifest or explicit cache invalidation registration.

## Serving Events

Use the serving callbacks when registration depends on Capell's own boot sequence:

```php
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Facades\CapellCore;

CapellCore::serving(function (): void {
    // Register Core runtime extensions here.
});

CapellAdmin::serving(function (): void {
    // Register Filament/admin extensions here.
});
```

`CapellCore::serving(...)` listens for `Capell\Core\Events\ServingCapell`, which is
dispatched once Core has registered its built-in assets and discoverable components.
`CapellAdmin::serving(...)` listens for `Capell\Admin\Events\ServingAdmin`, which is
dispatched from Filament's serving callback and therefore only runs when the admin panel
is serving.

Keep ordinary container bindings and configuration in the package service provider.
Use these callbacks for extension registration that must happen after the corresponding
Core or Admin surface is ready. There is no frontend serving event; frontend packages
register through their enabled frontend provider and the
[frontend extension points](frontend-extensions.md).

## Related Docs

- [Package anatomy](package-anatomy.md)
- [Service providers](service-providers.md)
- [Admin extensions](admin-extensions.md)
- [Frontend extensions](frontend-extensions.md)
- [Database and migrations](database-and-migrations.md)
- [Testing packages](testing-packages.md)
- [Command reference](../development/artisan-commands.md)
