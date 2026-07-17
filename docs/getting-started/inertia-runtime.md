# Capell Inertia Runtime

![Capell Inertia Runtime screenshot](../images/capell-readme-banner.jpg)

Use Inertia when a theme or package needs a Vue or React frontend while keeping Capell as the source of truth for sites, pages, layouts, public assets, and output safety.

The bridge is package-owned. A host app should install the runtime package, one client adapter, and any Inertia theme/component package it needs; it should not copy theme-specific Inertia setup into app code.

## Install And Enable

For a Vue app, install and enable the bridge plus the Vue adapter:

```bash
composer require capell-app/inertia capell-app/inertia-vue-adapter
php artisan capell:package-cache:clear
php artisan capell:package-cache
php artisan capell:extension-install capell-app/inertia --dry-run
php artisan capell:extension-install capell-app/inertia
php artisan capell:extension-install capell-app/inertia-vue-adapter
```

For React, replace the adapter package and set the adapter value in `.env`:

```bash
composer require capell-app/inertia capell-app/inertia-react-adapter
```

```dotenv
CAPELL_INERTIA_ADAPTER=react
```

Then install and enable `capell-app/inertia` and `capell-app/inertia-react-adapter` through the same `capell:extension-install` flow. In local development, add the package path repository for wherever those extension packages are checked out before requiring them.

## Packages

| Package                                   | Role                                                                                                                   |
| ----------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `capell-app/inertia`                      | Shared Laravel/Inertia bridge, root view, frontend renderer, middleware, adapter registry, and `CapellInertia` facade. |
| `capell-app/inertia-vue-adapter`          | Vue 3 client entrypoint, npm dependency registration, build asset registration, and adapter health check.              |
| `capell-app/inertia-react-adapter`        | React client entrypoint, npm dependency registration, build asset registration, and adapter health check.              |
| `capell-app/theme-inertia-bookings`       | Theme definition and booking-business demo install for the Inertia showcase.                                           |
| `capell-app/theme-inertia-bookings-vue`   | Vue components for the booking theme contract.                                                                         |
| `capell-app/theme-inertia-bookings-react` | React components for the same booking theme contract.                                                                  |

The generated extension catalogue lists current Inertia packages. The host [packages and extensions](../packages/catalog.md) page explains why that add-on inventory lives outside this repository.

## Runtime Selection

Inertia themes declare `FrontendRuntime::Inertia` in their theme definition. When that theme is active, `ResolveFrontendRuntimeAction` marks the runtime manifest with:

- `usesInertia = true`
- `modules['inertia'] = true`

`ResolveFrontendRuntimeAction` only marks the runtime manifest as using Inertia; it does not register or load client JavaScript. The theme or selected adapter must contribute its client runtime through a tagged `FrontendResourceContributor`, a typed `FrontendResourceRegistry` group, or an application Vite resource registration. The render performance report includes the `inertia` module flag so missing assets can be diagnosed alongside Blade and Livewire runtime concerns.

## Adapter Selection

The default adapter is Vue:

```dotenv
CAPELL_INERTIA_ADAPTER=vue
```

Use React by setting:

```dotenv
CAPELL_INERTIA_ADAPTER=react
```

The selected adapter must be installed and pass its extension health check. Both adapters expose the same server component names for shared Capell payloads:

| Component                 | Purpose                                                 |
| ------------------------- | ------------------------------------------------------- |
| `Capell/Page`             | Renders Capell CMS pages from public page/layout props. |
| `Capell/Bookings/Request` | Renders the public Bookings request flow.               |

The generic adapter packages register the adapter key, npm dependencies, and fallback build asset. A theme component package can register the active build asset for the same adapter; the Inertia Bookings Vue and React packs do this for the booking theme.

## Public Page Props

`capell-app/inertia` builds page props through `BuildInertiaPagePropsAction`. It reuses `BuildPublicPagePayloadAction` from the API package so HTTP API responses and Inertia props share the same sanitized public payload boundary.

For Inertia page rendering, the payload asks for:

- public page fields: `url`, `title`, `content`, and `meta`;
- layout data for all containers when a layout is present;
- Inertia widget component names for registered `FrontendInertia` widgets.

The existing `/api/capell/v1/pages/resolve` contract does not include widget component names by default. Component names are only added when the internal payload options set `includeWidgetComponents = true`, which is what the Inertia bridge does for page props.

## Package Routes

Package-owned routes should render through the bridge instead of calling Inertia directly:

```php
use Capell\Inertia\Facades\CapellInertia;

return CapellInertia::render('Capell/Bookings/Request', $props);
```

This keeps package routes on the Capell Inertia root view and shared public props. The Bookings theme uses this path for `/bookings`.

## Root View And Middleware

The Inertia package registers:

- a `FrontendResponseRenderer` for `FrontendRuntime::Inertia`;
- `HandleInertiaRequests` on the frontend route stack after `web`;
- the `capell-inertia::app` root Blade view.

The root view uses Capell's normal app head and body components, then mounts Inertia v3 with `<x-inertia::head />` and `<x-inertia::app />`. The default page component is `Capell/Page`; override it only through `capell-inertia.page_component` when a package intentionally owns a different page shell.

Shared middleware data is namespaced under public Capell keys. Do not share admin state, editor metadata, model IDs, field paths, package internals, signed admin URLs, or selectors through Inertia shared props.

## Safety Rules

Inertia output follows the same public-output contract as Blade output:

- public props must come from hydrated render data, Actions, or typed Data objects;
- public HTML and JSON must not expose authoring controls or internal identifiers;
- package route responses that handle private user intent should set their own cache and robots headers;
- public page props should reuse the API payload Actions instead of shaping ad hoc arrays in renderers.

Read next: [Inertia widgets](inertia-widgets.md) and [Inertia Bookings showcase](inertia-bookings-showcase.md).
