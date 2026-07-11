# Package Anatomy

![Capell Package Anatomy screenshot](../images/generated/admin/theme-library-admin-flow.png)

A typical local package lives in the consuming app's configured package path, such as `packages/<name>`.

For a new package, start with the scaffold command:

```bash
php artisan capell:make-extension vendor/example --profile=minimal --path=packages
php artisan capell:make-extension vendor/example-tools --profile=full --path=packages
```

```text
packages/example
├── README.md
├── capell.json
├── composer.json
├── config
├── database
│   ├── factories
│   ├── migrations
│   └── settings
├── docs
├── resources
│   ├── lang/en
│   └── views
├── routes
├── src
│   ├── Actions
│   ├── Data
│   ├── Filament
│   ├── Models
│   ├── Providers
│   └── Support
└── tests
```

Only create folders the package actually needs.

## Composer Metadata

Use a narrow package name and PSR-4 namespace:

```json
{
    "name": "capell-app/example",
    "type": "library",
    "autoload": {
        "psr-4": {
            "Capell\\Example\\": "src",
            "Capell\\Example\\Database\\Factories\\": "database/factories"
        }
    },
    "extra": {
        "laravel": {
            "providers": ["Capell\\Example\\Providers\\ExampleServiceProvider"]
        }
    }
}
```

Composer autoloading makes package classes available. `capell.json` tells Capell how the package is discovered, installed, and activated.

## Capell Manifest

`capell.json` uses manifest v3 metadata plus lifecycle and provider routing. Older manifest fields such as `capell-version` are not supported.

```json
{
    "manifest-version": 3,
    "name": "capell-app/example",
    "slug": "example",
    "displayName": "Example",
    "kind": "package",
    "visibility": "catalogue",
    "capellApiVersion": "^4.0",
    "version": "1.0.0",
    "description": "Example extension for Capell.",
    "product": {
        "group": "Capell Operations",
        "tier": "premium"
    },
    "namespace": "Capell\\Example\\",
    "surfaces": ["admin", "frontend", "console"],
    "dependencies": {
        "requires": ["capell-app/core", "capell-app/admin"],
        "supports": [],
        "conflicts": []
    },
    "providers": {
        "metadata": ["Capell\\Example\\Providers\\MetadataServiceProvider"],
        "install": ["Capell\\Example\\Providers\\InstallServiceProvider"],
        "runtime": ["Capell\\Example\\Providers\\ExampleServiceProvider"],
        "admin": ["Capell\\Example\\Providers\\AdminServiceProvider"],
        "frontend": ["Capell\\Example\\Providers\\FrontendServiceProvider"]
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
        "afterInstall": null,
        "afterInstallParams": [],
        "setup": null,
        "setupParams": [],
        "upgrade": null,
        "demo": null,
        "demoParams": [],
        "faker": null,
        "fakerParams": []
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
        "proposedLicense": "premium",
        "requestedCertification": "community",
        "supportPolicy": "community",
        "privateDocsRequested": false
    },
    "marketplace": {
        "summary": "Adds an example Capell extension.",
        "categories": ["example"],
        "screenshots": []
    }
}
```

Use `dependencies.requires` for packages that must be installed before this package can work. Use `dependencies.supports` for support packages that should be pulled into an install only when their own requirements are already selected or installed. Support packages that should not appear as standalone product choices should set `"visibility": "support"`; the installer can still add them through `dependencies.supports`.

The command map is lifecycle metadata. Capell reads install, after-install, setup, upgrade, demo, and faker command keys where those workflows apply. Package doctor and health checks are discovered from `capell:doctor` integration and the top-level `healthChecks` manifest list, not from command-map doctor or health entries.

Composer presence makes a package available. `capell_extensions.status = enabled` makes it active. Optional packages must not register runtime providers unless enabled.

`providers` is Capell's lifecycle-aware provider map. It is separate from Composer's `extra.laravel.providers`: Composer discovery makes classes available, while this map lets Capell register safe install providers separately from active runtime providers.

Provider keys:

- `metadata`: may load for discovered packages and must not change runtime behaviour.
- `install`: may load for console/installer workflows before the package is enabled.
- `runtime`: loaded only when the package is enabled.
- `admin`: loaded only when the package is enabled and the admin or console runtime is active.
- `frontend`: loaded only when the package is enabled and frontend rendering is active.

Use `surfaces` to declare the runtimes a package participates in, then use `providers` to route concrete service provider classes for those runtimes. This avoids booting admin code on frontend requests and keeps install-only wiring out of normal HTTP requests.

Use `product.group` and `product.tier` to keep the installer, Marketplace, and docs aligned with the package grouping. See [Package product groups](product-groups.md).

For the full install/audit flow, see [Extension lifecycle](extension-lifecycle.md).

## Naming

- Actions end in `Action`.
- Data objects end in `Data`.
- Settings classes end in `Settings`.
- Filament pages end in `Page`.
- Service providers live in `src/Providers`.
- User-facing strings go in package translations.
