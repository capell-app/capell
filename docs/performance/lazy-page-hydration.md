# Lazy Page Hydration

![Capell Lazy Page Hydration screenshot](../images/generated/admin/site-health-page.png)

> **Who is this for?**
> Developers working on Capell public rendering, cache hits, and runtime selection.

> **TL;DR:**
> Public rendering should not lazy-load page or site relations while deciding how a page renders. Capell keeps runtime decisions on already-loaded data and provides `LazyLoadedSiteContext` as an explicit helper, not as a default kernel step.

## When To Use This

Use this when a cached page response starts issuing database queries, Livewire assets appear on Blade-only pages, or a rendering strategy change touches page type metadata.

The practical goal is simple:

- Cache hits should be able to return public HTML without hydrating unrelated site data.
- Public views should receive hydrated render data instead of querying models directly.
- Runtime decisions should use page meta first, then only loaded type metadata.

## Current Runtime Shape

These pieces are involved in lazy-safe rendering decisions:

1. `RenderingStrategyMiddleware` reads the resolved page after the response has rendered and adds the `X-Rendering-Strategy` header.
2. `RenderingStrategyViewComposer` is registered on `capell::app` and adds a `runtimeManifest` plus `livewireEnabled` flag when one has not already been supplied.
3. `AbstractPage::pageRecordRequiresLivewire()` checks page meta first. It only consults page type meta or `is_livewire` when the `type` relation is already loaded.
4. `ResolveFrontendRuntimeAction` owns the main Blade, Livewire, and Inertia runtime decision for normal frontend rendering.
5. `LazyLoadedSiteContext` is an explicit helper for code paths that want a minimal `Site` wrapper. It is not currently part of the default frontend kernel pipeline.

The default frontend kernel steps are registered in `FrontendServiceProvider` through `config('frontend.kernel.steps')`. See [Frontend Page And Site Loading](../../packages/frontend/docs/page-site-loading.md) for the current step list.

## Rendering Strategies

`RenderingStrategyEnum` defines the public page rendering modes:

- **BladeOnly** (`blade`): plain Blade rendering with no Livewire runtime requirement.
- **BladeWithIslands** (`blade-islands`): Blade output with isolated Livewire components where needed.
- **FullLivewire** (`livewire`): the page itself renders as a Livewire component.

Page-level `meta.rendering_strategy` is the safest source because it is already on the page record. Type-level strategy and `is_livewire` are valid only when the page type relation has been loaded by the resolver or page cache.

## Loaded Type Rule

Do not write runtime checks that call `$page->blueprint` unless the relation is already loaded.

Use this pattern:

```php
$blueprint = $page instanceof Model && $page->relationLoaded('blueprint')
    ? $page->blueprint
    : null;
```

This protects public rendering from hidden database queries and from strict-model lazy-loading violations in tests.

## Public API: LazyLoadedSiteContext

`LazyLoadedSiteContext` lives at `packages/frontend/src/Support/Cache/LazyLoadedSiteContext.php`.

| Method                                               | Returns    | Purpose                                                                                 |
| ---------------------------------------------------- | ---------- | --------------------------------------------------------------------------------------- |
| `__construct(Site $minimalSite, Language $language)` | -          | Creates a wrapper around a minimal site and language.                                   |
| `site()`                                             | `Site`     | Loads the full site through `SiteLoader` on first access, then returns the loaded site. |
| `language()`                                         | `Language` | Returns the language passed at construction.                                            |
| `isFullyLoaded()`                                    | `bool`     | Reports whether `site()` has loaded the full site.                                      |
| `preloadSite()`                                      | `void`     | Explicitly loads the full site.                                                         |

Because this helper is explicit, code that adopts it should document where the wrapper is created and add tests proving when hydration does and does not happen.

## Detecting Unintended Hydration

Use query counts or strict lazy-loading tests for the code path you changed.

- For rendering strategy checks, assert that an unloaded `type` relation stays unloaded.
- For site wrappers, assert `LazyLoadedSiteContext::isFullyLoaded()` before and after the operation.
- For cache-hit behavior, listen for database queries around the request and prove the expected query budget.

## Gotchas

- Accessing `$page->blueprint` or `$site->relation` in public rendering code can trigger a query.
- `FullLivewire` pages intentionally need Livewire runtime state. Do not treat that as a cache leak by itself.
- `BladeWithIslands` should load Livewire assets for the island runtime, but it should not require full page Livewire hydration.
- Cached page models may already include `type`; ad hoc models in tests or package code may not.
- `LazyLoadedSiteContext` does not cache individual relations. Once `site()` is called, it stores the fully loaded `Site` object.

## Related

- [Frontend Page And Site Loading](../../packages/frontend/docs/page-site-loading.md)
- [Fragment caching](fragment-caching.md)
- [Cache invalidation](cache-invalidation.md)
- [ETag and conditional responses](etag-and-conditional-responses.md)
