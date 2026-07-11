# Architecture Diagrams

![Capell Architecture Diagrams screenshot](../images/capell-core-composition-erd.svg)

These diagrams are the source-of-truth architecture views for docs. Keep them exact and update them with code changes. Flux-generated companion images can sit near these diagrams, but Mermaid should remain the precise reference.

## Package Boot Lifecycle

```mermaid
flowchart TD
    Composer["Composer install/update"] --> ProviderDiscovery["Laravel provider discovery"]
    Composer --> Manifest["capell.json manifest"]
    Manifest --> PackageRegistry["CapellPackageRegistry"]
    PackageRegistry --> RuntimeContext["RuntimeContextResolver"]
    RuntimeContext --> Install["Install context"]
    RuntimeContext --> Runtime["Runtime context"]
    RuntimeContext --> Admin["Admin context"]
    RuntimeContext --> Frontend["Frontend context"]
    Install --> InstallWork["Migrations, setup Actions, install commands"]
    Runtime --> RuntimeWork["Settings, package metadata, subscribers, models"]
    Admin --> AdminWork["AdminBridge, Filament, extenders"]
    Frontend --> FrontendWork["Routes, hooks, assets, cache invalidation"]
```

## Admin Extender Resolution

```mermaid
flowchart LR
    PackageProvider["Package provider"] --> AdminBridge["AdminBridge"]
    PackageProvider --> TaggedExtenders["Tagged extenders"]
    AdminBridge --> Registrar["AdminBridgeRegistrar"]
    Registrar --> SurfaceRegistry["AdminSurfaceContributionRegistry"]
    TaggedExtenders --> Resolvers["Schema/action/table resolvers"]
    SurfaceRegistry --> Panel["CapellAdminPlugin"]
    Resolvers --> Filament["Filament resources/pages/widgets"]
    Panel --> Filament
```

## Frontend Public Render And Cache

```mermaid
flowchart TD
    Request["Public request"] --> Site["Site/domain resolution"]
    Site --> Language["Language resolution"]
    Language --> Page["Page URL resolution"]
    Page --> Hydration["Hydrated render payload"]
    Hydration --> Components["Components, hooks, media, assets"]
    Components --> Safety["Public HTML safety inspection"]
    Safety --> CacheDecision{"Safe to cache?"}
    CacheDecision -->|yes| Cache["Page/static/cache response"]
    CacheDecision -->|no| Bypass["Uncached response with bypass reason"]
```

## Marketplace Trust Flow

```mermaid
sequenceDiagram
    participant Admin
    participant CMS as Capell CMS
    participant App as Capell App
    participant Public as Public Host

    Admin->>CMS: Start account connection
    CMS->>App: Create connection session
    App-->>Admin: Approval URL
    Admin->>App: Approve account/site
    App->>CMS: Callback with code and state
    CMS->>App: Exchange code
    App-->>CMS: Instance ID and signing secret
    Admin->>CMS: Start domain verification
    CMS->>Public: Write .well-known challenge
    CMS->>App: Verify registration session
    App->>Public: Fetch challenge URL
    App-->>CMS: Verified domain
```

## Installer Browser Flow

```mermaid
flowchart TD
    Open["Open /install"] --> PageData["BuildInstallerPageDataAction"]
    PageData --> Form["Installer form"]
    Form --> Validate["InstallController validates input"]
    Validate --> Preflight["InstallerPreflight"]
    Preflight --> Decision{"Blocking failure?"}
    Decision -->|yes| Report["Show remediation and report"]
    Decision -->|no| Plan["Build install plan"]
    Plan --> Step["RunInstallStepAction"]
    Step --> Progress["Cache/File progress reporters"]
    Progress --> More{"More steps?"}
    More -->|yes| Step
    More -->|no| Success["Success page and optional installer removal"]
```

## Public Output Safety Boundary

```mermaid
flowchart LR
    AdminPage["Filament admin"] --> Beacon["Authenticated authoring beacon"]
    PublicPage["Public HTML"] --> Visitor["Anonymous/non-admin visitor"]
    PublicPage --> Cache["HTML/static cache/CDN"]
    Beacon --> AdminOnly["Admin-only edit controls"]
    AdminOnly -. "never cached" .-> Cache
```

## Flux Companion Asset Plan

The FLUX.2 connector is intended for visual companion diagrams, not exact API references. Generate assets under `docs/images/diagrams/` when the FLUX connector is authenticated:

| Asset                              | Use beside                                                      |
| ---------------------------------- | --------------------------------------------------------------- |
| `package-boot-lifecycle.png`       | [Package boot lifecycle](../packages/package-boot-lifecycle.md) |
| `admin-extender-resolution.png`    | [Admin debugging](../admin/debugging-admin-extensions.md)       |
| `frontend-public-render-cache.png` | [Frontend debugging](../frontend/debugging-public-output.md)    |
| `marketplace-trust-flow.png`       | [Marketplace debugging](../operations/debugging-marketplace.md) |
| `installer-browser-flow.png`       | [Installer overview](../../packages/installer/docs/overview.md) |

Keep generated text minimal. Use the Mermaid diagrams for exact symbols.
