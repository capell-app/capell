# Package Anatomy

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

Keep `extra.laravel.providers` to the package bootstrap provider only, and never repeat that class in a `capell.json` bucket. Composer discovery boots a provider unconditionally while the manifest buckets are gated on the extension being enabled, so a class named in both loads even when the package is disabled. Additional, gated providers — the metadata, install, admin, and frontend providers in the example below — belong in the manifest map and nowhere else.

## Capell Manifest

`capell.json` uses manifest v3 metadata plus lifecycle and provider routing. Older manifest fields such as `capell-version` are rejected by the manifest validator; manifest v3 uses `capellApiVersion`.

```json
{
    "manifest-version": 3,
    "name": "capell-app/example",
    "slug": "example",
    "displayName": "Example",
    "kind": "package",
    "visibility": "catalogue",
    "capellApiVersion": "^1.0",
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
        "runtime": ["Capell\\Example\\Providers\\RuntimeServiceProvider"],
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
    "actions": {
        "install": "Capell\\Example\\Actions\\InstallExampleAction",
        "setup": null,
        "uninstall": null,
        "afterInstall": null
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

Use `dependencies.requires` for packages that must be installed before this package can work. Keep it honest: a package that registers an admin page, an Extensions page action, a Filament resource, or admin translations must require `capell-app/admin`. Use `dependencies.supports` for support packages that should be pulled into an install only when their own requirements are already selected or installed. Support packages that should not appear as standalone product choices should set `"visibility": "support"`; the installer can still add them through `dependencies.supports`.

The command map is lifecycle metadata. Capell reads install, after-install, setup, upgrade, demo, and faker command keys where those workflows apply. It also reads `commands.doctor`: `capell:doctor` runs each installed package's declared doctor command as part of its report. Package *health checks* are separate, and come from the top-level `healthChecks` manifest list rather than the command map.

Composer presence makes a package available. `capell_extensions.status = enabled` makes it active. Optional packages must not register runtime providers unless enabled.

`providers` is Capell's lifecycle-aware provider map. It is separate from Composer's `extra.laravel.providers`: Composer discovery makes classes available, while this map lets Capell register safe install providers separately from active runtime providers.

Provider keys:

- `metadata`: may load for discovered packages and must not change runtime behaviour.
- `install`: may load for console/installer workflows before the package is enabled.
- `runtime`: loaded only when the package is enabled.
- `admin`: loaded only when the package is enabled. Use it to group admin providers.
- `frontend`: loaded only when the package is enabled. Use it to group frontend providers.

`runtime`, `admin`, `frontend`, and `auth` are resolved together behind a single enabled check — the bucket names document intent and keep registration organised, but they do **not** filter by request context. An `admin` provider still loads on frontend requests once the package is enabled, so guard admin-only work inside the provider rather than relying on the bucket.

Use `surfaces` to declare the runtimes a package participates in, then use `providers` to route concrete service provider classes for those runtimes. This keeps install-only wiring out of normal HTTP requests and documents which provider owns which responsibility. It does not gate by request context — see the note above — so guard admin-only work inside the provider rather than relying on the bucket name.

Use `product.group` and `product.tier` to keep the installer, Marketplace, and docs aligned with the package grouping. See [Package product groups](product-groups.md).

The manifest above shows both lifecycle blocks so you can recognise them, but a new package should declare **only `actions`**. `actions` holds class-strings implementing `PackageLifecycleAction`; `commands` holds legacy artisan command names and is refused by web-triggered installs, so a `commands`-only package cannot be installed from the admin Marketplace. The Foundation packages still ship `commands` because they predate the Action contract. See [Lifecycle: actions and commands](build-extension-end-to-end.md#lifecycle-actions-and-commands).

Declare the Capell API range your package supports with `capellApiVersion` — see [Extension API versioning](extension-api-versioning.md).

For the full install/audit flow, see [Extension lifecycle](extension-lifecycle.md).

## Naming

- Actions end in `Action`.
- Data objects end in `Data`.
- Settings classes end in `Settings`.
- Filament pages end in `Page`.
- Service providers live in `src/Providers`.
- User-facing strings go in package translations.
