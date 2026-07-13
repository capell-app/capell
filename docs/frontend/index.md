# Frontend

Use this section if you build or test the public site with Blade, Livewire, or Inertia.

## Render Pages Safely

| I need to...                               | Read                                                                       |
| ------------------------------------------ | -------------------------------------------------------------------------- |
| Protect anonymous public output            | [Public HTML safety](public-html-safety.md)                                |
| Understand the render pipeline             | [Frontend guide](guide.md)                                                 |
| Resolve site, page, and language loading   | [Page and site loading](../../packages/frontend/docs/page-site-loading.md) |
| Configure server fallback and static cache | [Server config](../../packages/frontend/docs/server-config.md)             |
| Work with themes                           | [Frontend themes](themes.md)                                               |
| Render media safely                        | [Media rendering](media-rendering.md)                                      |

## Add Frontend Capabilities

| I need to...                         | Read                                                                   |
| ------------------------------------ | ---------------------------------------------------------------------- |
| Choose how to compose a page         | [Build a page](../getting-started/building-pages.md)                   |
| Add and configure frontend widgets   | [Widgets](widgets.md)                                                  |
| Build with Inertia                   | [Capell Inertia runtime](../getting-started/inertia-runtime.md)        |
| Add interactive widgets or fragments | [Capell Interactions](../getting-started/capell-interactions.md)       |
| Register render hooks                | [Render hooks](../../packages/frontend/docs/extending-render-hooks.md) |
| Register package Tailwind assets     | [Tailwind assets](../../packages/frontend/docs/tailwind-assets.md)     |
| Enable Blaze safely                  | [Blaze support](blaze-support.md)                                      |
| Find optional frontend packages      | [Packages and extensions](../packages/catalog.md)                      |

## Test And Troubleshoot

| I need to...                          | Read                                                                 |
| ------------------------------------- | -------------------------------------------------------------------- |
| Test public output                    | [Frontend testing](../../packages/frontend/docs/testing-frontend.md) |
| Debug public output or cache bypasses | [Debugging public output](debugging-public-output.md)                |
| Resolve symlinked Tailwind CSS issues | [Tailwind v4 with symlinked vendor CSS](tailwind-vendor-css.md)      |

## Public HTML Safety

Anonymous users, signed-in non-admin users, crawlers, cached HTML, and static exports must never receive authoring markers, model IDs, field paths, permissions, package names, selectors, signed editor URLs, or editor scripts.

In-page editing belongs to `capell-app/frontend-authoring`. It decorates the page only after an authenticated admin beacon response.

Use the [public HTML safety contract](public-html-safety.md) before changing rendering, themes, cache output, render hooks, or frontend package views. Public Blade should receive hydrated render data rather than query or lazy-load models.

Use [Operations](../operations/index.md) for production cache and stale-output incidents.
