# Frontend Page And Site Loading

![Capell Frontend Page And Site Loading screenshot](./images/screenshots/frontend-published-page.png)

Capell frontend requests resolve site, language, page, layout, and theme state before the public renderer runs.

Use this when changing `capell-app/frontend` routing, cache behavior, runtime selection, or request-scoped frontend state.

## High-level flow

- A request enters the app and the Frontend middleware invokes the Frontend Kernel.
- The Kernel builds a FrontendWork with the HTTP request and a FrontendState (scoped per request).
- The Kernel runs a sequence of steps (a Laravel Pipeline) to resolve site/domain/language, page, layout, theme, and build a renderable context.
- The result is a `FrontendBootstrapResult` that may contain:
    - `redirect`: an early redirect, such as a default-site redirect.
    - `error`: HTTP error details, such as a 404 for bots.
    - `context`: the resolved `FrontendContext` used by rendering.

## Container bindings (service provider)

`FrontendServiceProvider` registers the key interfaces used by the pipeline:

- `UrlSignatureVerifierInterface` maps to `FrontendUrlSignatureService`.
- `AssetsRegistryInterface` maps to `FrontendAssetsService`.
- `FrontendSettingsReaderInterface` maps to `FrontendSettingsReader`.
- `SettingsMigrationProviderInterface` maps to `FrontendSettingsMigrationProvider`.
- `FontMimeTypeResolverInterface` maps to `FontMimeTypeResolver`.
- `FrontendResponseRendererRegistry` is scoped and registers Blade and Livewire response renderers.
- `RenderedModelTracker` is scoped and defaults to `NullRenderedModelTracker`.

Scoped state:

- `FrontendState` is scoped per request.
- `FrontendContextReader` resolves to `FrontendState`.
- `FrontendKernelInterface` is scoped and receives the scoped `FrontendState`.

Kernel registration:

- `FrontendKernelInterface` is bound to `FrontendKernel` with the steps from `config('frontend.kernel.steps')`.
- The default steps are:
    1. `ParseUrlStep`
    2. `SiteResolveStep`
    3. `SetUrlGeneratorStep`
    4. `NormalizeDomainPathStep`
    5. `PageResolveStep`
    6. `LayoutResolverStep`
    7. `ThemeResolverStep`
    8. `RegisterThemeViewsStep`
    9. `NotifySubscribersStep`

Aux services:

- `ThemeViewRegistrar` is a singleton. It implements Core's Octane reset contract so a theme registered for one request cannot remain in the `capell::` view namespace for the next long-running worker request.
- `FrontendCachePolicy` is a singleton.
- `LayoutWidgetRegistry` from `capell/core` provides frontend Blade and Livewire component aliases.
- `OnFrontendContextResolved` is a singleton listener.
- The URL generator binding switches to `SiteUrlGenerator` when `capell-frontend.use_site_domain_for_urls` is true. Otherwise it uses Laravel's `UrlGenerator`.

## Runtime manifest contributors

`ResolveFrontendRuntimeAction` owns the generic Blade, Livewire, and Inertia runtime decision. Optional packages that need extra public frontend runtime flags should not be referenced directly from `capell/frontend`; they should implement `Capell\Frontend\Contracts\FrontendRuntimeManifestContributor` and tag the implementation with `FrontendRuntimeManifestContributor::TAG`.

Contributors receive the resolved `FrontendContextReader` and mutable `FrontendRuntimeManifestData`, letting add-ons contribute runtime flags while frontend remains safe to install on its own.

## Pipeline steps (summary)

- `ParseUrlStep`: normalizes the request path and saves it into state.
- `SiteResolveStep`: resolves the site, language, domain, and normalized path from the full URL. It may redirect to the default site when enabled.
- `SetUrlGeneratorStep`: applies the frontend URL generator state for the resolved request, then restores Laravel's previous forced root and scheme when the step finishes or throws.
- `NormalizeDomainPathStep`: removes the domain path prefix, such as `/en`, from the effective URL to obtain the page-relative path.
- `PageResolveStep`: resolves the target page, supports wildcard routes, error page fallback, and returns 404 for bot user agents when no page is found.
- `LayoutResolverStep`: chooses the layout for the resolved page.
- `ThemeResolverStep`: chooses the theme for rendering.
- `RegisterThemeViewsStep`: registers theme view paths for Blade.
- `NotifySubscribersStep`: emits `FrontendContextResolved` with an immutable snapshot of the scoped state.

## Error and redirect handling

- Redirects short-circuit the pipeline and return immediately from the Kernel.
- Errors also short-circuit; bots receive an immediate 404 when the page cannot be resolved.

## Config toggles

- `capell-frontend.redirect_default_site`: When true, unresolved domains redirect to the default enabled SiteDomain.
- `capell-frontend.use_site_domain_for_urls`: Switches URL generation to include site domain rules.
- `capell-frontend.debug_log`: Adds extra debug logging in resolution steps.
- `frontend.kernel.steps`: Overrides the pipeline step list when an application needs a custom frontend bootstrap sequence.

## Caching

- `FrontendCachePolicy`, `PageModelCache`, `PublicPageRenderDataCache`, `PageListingCache`, and `PageHydrator` manage frontend cache behavior.
- Read the [Frontend guide](../../../docs/frontend/guide.md) for HTML caching details.

## Long-running worker safety

Frontend keeps request-specific rendering state out of public singletons wherever possible. When a singleton is unavoidable, it must implement `Capell\Core\Octane\Resettable` and be tagged with `Resettable::TAG` so Core can flush it after an Octane operation.

The built-in resettable frontend services are:

- `ThemeViewRegistrar`, which restores the default `capell::` Blade namespace.
- `CacheInvalidationRegistry`, which clears in-memory dependency registrations.
- `FragmentCacheDirective`, which clears directive compile state.

Do not store the current site, page, language, theme, preview context, signed URL, or admin/editor state in an untagged singleton. Use scoped bindings for request state and use resettable singletons only for services that genuinely need to survive container boot.

## Testing coverage

Unit and integration tests validate the pipeline contracts:

- Integration: FrontendKernelTest boots the kernel end-to-end.
- Unit and integration coverage includes kernel steps, rendering strategy behavior, site/page loader behavior, cache helpers, and public rendering contracts.
