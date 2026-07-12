# Frontend

Capell Frontend resolves public requests and renders published pages. It owns site/page loading, layout/theme context, frontend settings, cache-aware rendering, public HTML safety, and package-facing frontend hooks.

> **Who's this for?** Developers building the public site (Blade, Livewire, Inertia) on Capell. Rendering, themes, assets, caching, and safety.

Status: `Available` · Package: `capell-app/frontend`

## Request Flow

1. Resolve the current site from the request host and configured fallback rules.
2. Resolve the language and page URL for that site.
3. Load the page, layout, theme, blueprint, translations, and render variables before Blade receives the view.
4. Render the public page through frontend components, render hooks, media helpers, and registered assets.
5. Apply cache headers, ETags, static cache integration, and minification when configured.

Public Blade views should receive hydrated render data. Do not query models, lazy-load relationships, or fetch package state from public views.

## Public HTML Safety

Anonymous users, signed-in non-admin users, crawlers, cached HTML, and static exports must never receive authoring markers, model IDs, field paths, permissions, package names, selectors, signed editor URLs, or editor scripts.

In-page editing belongs to `capell-app/frontend-authoring`. It decorates the page only after an authenticated admin beacon response.

Use the [public HTML safety contract](public-html-safety.md) when changing rendering, themes, cache output, render hooks, or frontend package views.

## Main Extension Points

| Need                               | Use                                                                                        |
| ---------------------------------- | ------------------------------------------------------------------------------------------ |
| Inject public HTML                 | `RenderHookRegistry::register(RenderHookLocation::..., ...)`.                              |
| Register frontend widgets          | `LayoutWidgetRegistry::register($name, LayoutWidgetTarget::FrontendBlade, $component)`.    |
| Register Livewire frontend widgets | `LayoutWidgetRegistry::register($name, LayoutWidgetTarget::FrontendLivewire, $component)`. |
| Register Inertia frontend widgets  | `LayoutWidgetDefinitionData::frontendInertia($key, $component)`.                           |
| Add CSS/JS source paths            | `TailwindAssetsRegistry::registerSource(...)` and `registerImport(...)`.                   |
| Invalidate pages for model changes | `CacheInvalidationRegistry::registerDependency(...)`.                                      |
| Render media safely                | Use the Capell media helpers/components and pass localized alt text from prepared data.    |

Render hooks are for small, safe additions. If a package needs a major page region, add an explicit frontend component or package view instead of hiding large behavior in a hook.

## Server Setup

Capell works through Laravel routes without web-server rewrites. For static HTML cache performance, configure Nginx or Apache to check generated cache files before PHP and to fall back to Laravel when no cache file exists.

Keep these rules in deploy docs, not inside themes:

- static cache directory is served as ordinary public HTML
- PHP fallback still works when cache files are missing or stale
- lockdown/maintenance responses must bypass stale static pages
- cache files must stay safe for anonymous visitors

## Frontend Assets

Packages should register Tailwind sources and imports in PHP registration code. Do not ask app developers to manually copy package paths into every project unless the package genuinely cannot register itself.

For local package development, unresolved vendor CSS imports usually mean Vite cannot resolve a symlinked dependency. Run the package-owned asset report command when available, then install missing npm dependencies or add a narrow Vite alias.

## Testing

Use focused tests around frontend behavior:

- route resolves the expected site/page/language
- public responses contain expected content
- anonymous/non-admin HTML does not contain authoring controls or internal identifiers
- cache headers and ETags match the configured behavior
- render hooks add only the intended HTML
- public Blade does not rely on lazy-loaded relationships

For public-output safety, assert absence as well as presence.

Use `sinnbeck/laravel-dom-assertions` when the element, region, attribute, or repeated component count matters. App/theme tests can request seeded public pages directly; package tests can use factories when they need to own the fixture. The frontend package guide has copy-pasteable examples.

## Read Next

| Need                                             | Read                                                                       |
| ------------------------------------------------ | -------------------------------------------------------------------------- |
| Choose page composition                          | [Build a page](../getting-started/building-pages.md)                       |
| Understand the render pipeline                   | [Frontend guide](guide.md)                                                 |
| Resolve site/page/language loading               | [Page and site loading](../../packages/frontend/docs/page-site-loading.md) |
| Configure server fallback and static cache rules | [Server config](../../packages/frontend/docs/server-config.md)             |
| Register render hooks                            | [Render hooks](../../packages/frontend/docs/extending-render-hooks.md)     |
| Understand theme runtime                         | [Frontend themes](themes.md)                                               |
| Register package Tailwind assets                 | [Tailwind assets](../../packages/frontend/docs/tailwind-assets.md)         |
| Compile symlinked vendor CSS with Tailwind v4    | [Tailwind v4 + symlinked vendor CSS](tailwind-vendor-css.md)               |
| Render media safely                              | [Media rendering](media-rendering.md)                                      |
| Add frontend widgets                             | [Widgets](widgets.md)                                                      |
| Register a widget and load its assets            | [Widget registration](widget-registration.md)                              |
| Set widget instance and presentation state       | [Widget state](widget-state.md)                                            |
| Open lazy widget and fragment targets            | [Widget and fragment targets](widget-targets.md)                           |
| Build with Inertia                               | [Capell Inertia runtime](../getting-started/inertia-runtime.md)            |
| Build interactive widget and fragment targets    | [Capell Interactions](../getting-started/capell-interactions.md)           |
| Support Blaze templates                          | [Blaze support](blaze-support.md)                                          |
| Test public output                               | [Frontend testing](../../packages/frontend/docs/testing-frontend.md)       |
| Debug public output or cache bypasses            | [Debugging public output](debugging-public-output.md)                      |

## Optional Frontend Features

| Feature                          | Package                         |
| -------------------------------- | ------------------------------- |
| Minimal default frontend theme   | `capell-app/frontend`           |
| In-page authoring                | `capell-app/frontend-authoring` |
| XML sitemaps and discovery pages | `capell-app/site-discovery`     |
| SEO metadata and audits          | `capell-app/seo-suite`          |
| Static HTML cache                | `capell-app/html-cache`         |
| Site search                      | `capell-app/search`             |
| Inertia runtime bridge           | `capell-app/inertia`            |

Use [Operations](../operations/index.md) for production cache and stale-output troubleshooting.
