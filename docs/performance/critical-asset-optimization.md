# Frontend Asset Optimization

> **Who is this for?**
> Frontend package developers tuning public CSS, JavaScript, preload hints, and DNS prefetch without leaking package/editor internals into cached HTML.

> **TL;DR:** Package runtime assets are declared through `FrontendAssetContributor` implementations or `FrontendResourceRegistry` groups. `AssetOptimizationMiddleware` is available as the opt-in `frontend.asset-optimization` route middleware alias and injects safe response hints from the resolved public frontend context.

## When To Use This

Register assets when:

- a widget or public component needs CSS or JavaScript only when it appears on the page;
- a package needs to contribute runtime manifest hints;
- a theme asset host should be hinted with DNS prefetch;
- non-critical JavaScript should load lazily through the frontend asset manifest.

Do not add asset tags with render hooks when those tags belong to a reusable widget or block. Use render hooks for small public HTML fragments only.

## How It Is Wired

Packages have two supported asset paths:

- `FrontendResourceRegistry` groups for widget-scoped CSS and JavaScript.
- Tagged `FrontendAssetContributor` implementations for lower-level runtime asset manifest contributions.

`AssetOptimizationMiddleware` is registered as `frontend.asset-optimization`. It is not globally applied; attach it to the route group or route that should receive response hints:

```php
Route::middleware(['frontend.asset-optimization'])->group(function (): void {
    // routes here can receive safe asset hints
});
```

## Widget Resource Groups

Use `FrontendResourceRegistry` when the asset belongs to a widget, block, or public component that declares its resource group.

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Capell\LayoutBuilder\Data\LayoutWidgets\LayoutWidgetDefinitionData;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\LayoutBuilder\Support\LayoutWidgets\LayoutWidgetRegistry;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;
use Illuminate\Support\ServiceProvider;

final class FrontendServiceProvider extends ServiceProvider
{
    public function boot(FrontendResourceRegistry $resources, LayoutWidgetRegistry $widgets): void
    {
        $resources
            ->group('example.carousel')
            ->css('resources/css/carousel.css', buildPath: 'vendor/example')
            ->js('resources/js/carousel.js', buildPath: 'vendor/example', loading: PresentationLoadingStrategy::Visible);

        $widgets->registerDefinition(LayoutWidgetDefinitionData::frontendBlade(
            key: 'carousel',
            component: 'example::widgets.carousel',
            resourceGroups: ['example.carousel'],
        ));
    }
}
```

The public asset manifest exposes generated resource IDs. Keep package names, model IDs, field paths, signed URLs, and editor metadata out of rendered public HTML.

## Asset Contributors

Use `FrontendAssetContributor::TAG` when a package needs to contribute directly to the frontend asset manifest instead of a widget resource group. Register contributors as tagged container services from the package provider.

## AssetOptimizationMiddleware Behaviour

When a response passes through `AssetOptimizationMiddleware`:

1. It only changes HTTP 200 HTML responses.
2. It reads the current frontend context.
3. It injects safe DNS prefetch hints for the resolved theme asset URL.
4. It leaves the response unchanged if context is unavailable.

The middleware reads resolved public render/context state. It does not use static package state.

## Related

- [Performance index](README.md)
- [Fragment caching](fragment-caching.md)
- [ETag & conditional responses](etag-and-conditional-responses.md)
- [Cache invalidation](cache-invalidation.md)
