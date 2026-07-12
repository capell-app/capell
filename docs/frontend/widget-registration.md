# Widget Registration

Register a frontend widget so editors can choose it and Capell can render its Blade, Livewire, or Inertia component. Registration is also where you declare the resource groups that load the widget's CSS and JavaScript.

## Register A Widget

Use `LayoutWidgetRegistry::registerDefinition()` when the widget needs presentation defaults, runtime resource groups, or interaction defaults.

```php
use Capell\LayoutBuilder\Data\LayoutWidgets\LayoutWidgetDefinitionData;
use Capell\LayoutBuilder\Support\LayoutWidgets\LayoutWidgetRegistry;

public function boot(LayoutWidgetRegistry $widgets): void
{
    $widgets->registerDefinition(LayoutWidgetDefinitionData::frontendBlade(
        key: 'video-player',
        component: 'vendor-video::widgets.player',
        resourceGroups: ['vendor-video.player'],
        defaultPresentationSettings: [
            'width_mode' => 'container',
            'loading_strategy' => 'interaction',
        ],
    ));
}
```

`LayoutWidgetRegistry::register($name, LayoutWidgetTarget::FrontendBlade, $component)` still works for simple widgets. New package code should prefer definitions because the rendering component, resource groups, presentation defaults, and interaction defaults stay in one place.

## Inertia Widgets

Inertia widgets use the same registry and definition shape, but they target `LayoutWidgetTarget::FrontendInertia` through `LayoutWidgetDefinitionData::frontendInertia()`.

```php
use Capell\LayoutBuilder\Data\LayoutWidgets\LayoutWidgetDefinitionData;
use Capell\LayoutBuilder\Support\LayoutWidgets\LayoutWidgetRegistry;

public function boot(LayoutWidgetRegistry $widgets): void
{
    $widgets->registerDefinition(LayoutWidgetDefinitionData::frontendInertia(
        key: 'booking-card',
        component: 'Capell/Widgets/BookingCard',
        resourceGroups: ['example.booking-card'],
    ));
}
```

The `component` value is the public server component name that the selected Vue or React adapter resolves. Core Frontend registers `content`, `image`, and `title` as `Capell/Widgets/Content`, `Capell/Widgets/Image`, and `Capell/Widgets/Title`. Do not use PHP class names, vendor package slugs, database identifiers, or admin-only labels as Inertia component names.

Inertia page props include widget component names only when the payload builder is called with `includeWidgetComponents = true`. The Inertia bridge does that for page props; the public page resolve API keeps its existing response shape.

Read [Inertia widgets](../getting-started/inertia-widgets.md) for the setup and lazy-loading rules.

## Runtime Resources

Use `FrontendResourceRegistry` when a widget needs CSS or JavaScript that should only load when the widget appears or when an interaction target opens.

```php
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;

public function boot(FrontendResourceRegistry $resources): void
{
    $resources
        ->group('vendor-video.player')
        ->css('resources/css/player.css', buildPath: 'vendor/vendor-video')
        ->js('resources/js/player.js', buildPath: 'vendor/vendor-video', loading: PresentationLoadingStrategy::Interaction);
}
```

For package-provided defaults that should appear in diagnostics and admin resource selectors, register metadata with `register()`:

```php
$resources->register(
    key: 'vendor-video.player',
    label: 'Video player',
    assets: [
        ['source' => 'resources/css/player.css'],
        [
            'source' => 'resources/js/player.js',
            'loadingStrategy' => PresentationLoadingStrategy::Interaction->value,
            'defer' => true,
        ],
    ],
    description: 'Video player runtime resources.',
    package: 'capell-app/vendor-video',
    defaultBuildPath: 'vendor/vendor-video',
);
```

Theme `editor.resources` definitions remain supported and override package defaults with the same group key. If the theme has no local definition, Capell falls back to loaded theme blueprint/type metadata, then package defaults.

Editor-selected widget groups are stored on the widget instance under `data.__capell.resources.groups`. Layout Builder stores the same selection under block `meta.resources.groups`. Per-instance strategy overrides live under `loading_overrides` with the group key and `loading_strategy`.

During public rendering Capell resolves those selected groups through the same theme/package registry and folds them into the asset manifest. Eager resources load with the page; `visible`, `interaction`, and `idle` resources are exposed through generated public resource IDs for the frontend runtime.

Public HTML receives generated resource IDs. Resource group keys and package names stay out of the rendered page.

## Next

- [Frontend widgets](widgets.md)
- [Widget state](widget-state.md)
- [Widget and fragment targets](widget-targets.md)
- [Inertia widgets](../getting-started/inertia-widgets.md)
