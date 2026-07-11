# Presentation Delivery

![Capell Presentation Delivery screenshot](./images/screenshots/frontend-published-page.png)

Presentation delivery is the shared runtime model for public widgets and Layout Builder blocks. It covers three related concerns:

| Concern      | What it controls                                                                                        |
| ------------ | ------------------------------------------------------------------------------------------------------- |
| Presentation | Preset, width, alignment, visibility, and responsive display choices                                    |
| Delivery     | Server-rendered output, lazy fragment output, loading strategy, connection rules, and runtime resources |
| Interactions | Public triggers that open lazy widgets, lazy fragments, safe URLs, or public-action fallbacks           |

These settings are deliberately stored as Capell metadata, not widget props. Public rendering uses the metadata to decide wrappers, lazy placeholders, assets, and interaction triggers, then strips the metadata before a widget component receives its normal content data.

## Storage Map

| Surface                           | Presentation path                                          | Interaction path                                          |
| --------------------------------- | ---------------------------------------------------------- | --------------------------------------------------------- |
| Content widget instance           | `data.__capell.presentation`                               | `data.__capell.interactions`                              |
| Widget type default               | `LayoutWidgetDefinitionData::$defaultPresentationSettings` | `LayoutWidgetDefinitionData::$defaultInteractionTriggers` |
| Layout Builder block instance     | layout block `meta.presentation`                           | layout block `meta.interactions`                          |
| Layout Builder block type default | type `meta.presentation`                                   | type `meta.interactions`                                  |

## Resolution Order

Presentation settings resolve in this order:

1. instance override;
2. type default;
3. presentation preset default;
4. system default.

Existing content falls back to `server_rendered`, all devices, any connection, eager loading, inherited width, and stretch alignment.

Use `Capell\Core\Actions\Presentation\ResolvePresentationSettingsAction` when package code needs normalized settings for a widget or block. The resolver is the boundary that turns sparse editor state into complete public-safe settings.

Do not read these arrays directly in public views. Resolve them in Actions, payload builders, view components, or package render code, then pass only the public-safe result to Blade.

## Interaction Data

Use `Capell\Core\Actions\Interactions\ResolveInteractionTriggersAction` to normalize interaction state. The resolver accepts instance triggers first and type defaults second. Instance triggers replace type defaults when present.

Each trigger can contain:

| Key                  | Required             | Notes                                                                               |
| -------------------- | -------------------- | ----------------------------------------------------------------------------------- |
| `label`              | Yes                  | Public button/link text.                                                            |
| `icon`               | No                   | Admin/display hint, usually a Heroicon key.                                         |
| `style`              | No                   | `primary`, `secondary`, or `subtle`.                                                |
| `target_type`        | Yes                  | `widget`, `fragment`, `url`, or `public_action`.                                    |
| `behavior`           | For lazy targets     | `modal`, `slide_over`, `inline_reveal`, or `replace_region`.                        |
| `target_widget`      | For widget targets   | A one-item widget builder payload, e.g. `[['type' => 'content', 'data' => [...]]]`. |
| `fragment_reference` | For fragment targets | Encrypted reference. Layout Builder can default this to the current block fragment. |
| `url`                | For URL targets      | Must be `/`, `#`, `http://`, or `https://`.                                         |
| `fallback_url`       | No                   | Safe fallback URL for failed or external targets.                                   |
| `analytics_key`      | No                   | Public-safe key for analytics wiring.                                               |
| `aria_label`         | No                   | Falls back to `label`.                                                              |
| `modal_size`         | No                   | `sm`, `md`, `lg`, `xl`, or `screen`.                                                |

Invalid triggers are dropped instead of rendering broken public controls.

## How A Lazy Widget Interaction Works

Use a lazy widget when a trigger should open content that is best modelled as a Capell widget: video, gallery, form, calculator, comparison table, product picker, or any package-owned component with its own resources.

1. The editor adds an interaction to a visible widget or block.
2. The interaction target type is `widget`.
3. The nested `target_widget` payload stores one normal widget.
4. Public rendering turns that payload into an encrypted widget reference.
5. The trigger points at `/_capell/widgets/{reference}`.
6. On click, the frontend runtime fetches the target, mounts it using the chosen behaviour, loads nested resources, and activates nested interactions.

The initial public page does not include the target widget's content, widget key, component name, package namespace, or raw data.

## How A Lazy Fragment Interaction Works

Use a lazy fragment when the target is a Layout Builder public block fragment rather than a standalone widget. This is for heavier page sections that already live in the layout graph.

1. The block or block type has an interaction whose target type is `fragment`.
2. If no explicit `fragment_reference` is stored, Layout Builder can generate an encrypted reference to the current block during public rendering.
3. The trigger points at `/_capell/fragments/{reference}`.
4. The fragment endpoint decodes the reference, revalidates the page/layout/block scope, renders only that public block fragment, and runs the public HTML safety inspection.
5. Invalid, replayed, or unsafe references return the same generic failure.

This is separate from normal page HTML caching. Fragment responses have fragment-specific cache headers and must not be treated as full page responses.

## Widget Resource Groups

Frontend widgets can declare CSS and JavaScript resources in PHP registration code. The public page render collects the widget definitions that appear in page content, adds eager resources to the normal asset manifest, and exposes lazy resources through a small JSON manifest consumed by `resources/js/widget-runtime.js`.

```php
use Capell\LayoutBuilder\Data\LayoutWidgets\LayoutWidgetDefinitionData;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\LayoutBuilder\Support\LayoutWidgets\LayoutWidgetRegistry;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;

public function boot(FrontendResourceRegistry $resources, LayoutWidgetRegistry $widgets): void
{
    $resources
        ->group('vendor.carousel')
        ->css('resources/css/widgets/carousel.css', buildPath: 'vendor/theme')
        ->js('resources/js/widgets/carousel.js', buildPath: 'vendor/theme', loading: PresentationLoadingStrategy::Visible);

    $widgets->registerDefinition(LayoutWidgetDefinitionData::frontendBlade(
        key: 'carousel',
        component: 'vendor-theme::widgets.carousel',
        resourceGroups: ['vendor.carousel'],
    ));
}
```

Use stable resource group keys. Public HTML receives generated resource IDs, not package names, component names, model IDs, field paths, or editor metadata.

Instance-level admin selections use the same manifest path. Content widgets store selected groups at `data.__capell.resources.groups` and loading overrides at `data.__capell.resources.loading_overrides`. Layout Builder blocks store them at `meta.resources.groups` and `meta.resources.loading_overrides`.

The renderer resolves selected groups through `ThemeResourceResolver`, applies valid per-group loading overrides, and keeps the original pre-deduped requirements for diagnostics. That lets conflict detection report incompatible strategies even when public delivery correctly emits a single asset.

The public manifest consolidates resources by resolved URL and kind. When the same URL is requested with different strategies, delivery promotes it deterministically in this order: eager, visible, idle, then interaction. Every lazy public ID remains mapped to the server-registered resource, while the browser loads the shared URL once.

## Delivery Mode: Lazy Fragments

Lazy fragments are Layout Builder public block fragments only. They are enabled by setting presentation delivery mode to `lazy_fragment` on the block instance or block type default.

The public placeholder contains only generic fragment markers and an encrypted fragment URL under `/_capell/fragments/{reference}`. The reference is opaque encrypted JSON and is revalidated against site, page, layout, language, container, block, and occurrence before rendering. Invalid or replayed references return a generic 404.

Fragment responses use fragment-specific cache headers and do not enter the normal page HTML cache. Above-the-fold content should remain server-rendered; lazy fragments are for below-the-fold, expensive, or optional sections.

Public fragment HTML is inspected with the same authoring-surface safety check as full page output. Do not emit editor URLs, signed admin URLs, model IDs, field paths, component names, package namespaces, block keys, or other authoring details.

## Interactions

Capell interactions let a widget or Layout Builder block expose a public trigger that opens another experience. Targets can be registered widgets, encrypted Layout Builder fragments, safe URLs, or public-action fallbacks. Widget targets are rendered through `GET /_capell/widgets/{reference}` using encrypted opaque JSON, so public HTML contains the trigger label and generic runtime attributes but not the widget type, component name, package namespace, model IDs, field paths, or target widget content.

Supported behaviours are modal, slide-over, inline reveal, and replace region. The frontend runtime handles fetching the lazy target, loading nested widget resources, keyboard close behaviour, and nested fragment/widget activation. Editors configure target widgets through the normal widget builder inside the interaction form.

Store widget interactions under `data.__capell.interactions`. Layout Builder block instances store interactions under layout block `meta.interactions`, and block type defaults use type `meta.interactions`.

## Lazy Widgets

Lazy widget targets use `Capell\Frontend\Support\Widgets\OpaqueWidgetReference` and `Capell\LayoutBuilder\Actions\LayoutWidgets\RenderLazyLayoutWidgetAction`.

The encrypted reference contains:

```php
[
    'type' => 'video-player',
    'data' => [
        'title' => 'Product walkthrough',
        'video_url' => 'https://example.com/video.mp4',
        '__capell' => [
            'presentation' => [
                'loading_strategy' => 'interaction',
            ],
        ],
    ],
]
```

The lazy widget endpoint validates that the widget type is registered for `LayoutWidgetTarget::FrontendBlade`, renders the target through the normal widget runtime wrapper, runs public HTML safety inspection, and returns generic failure for invalid references.

## Admin UX

`Capell\Admin\Filament\Components\Forms\Interactions\InteractionSettingsSchema` provides the shared editor controls. Content Builder stores widget interactions under `data.__capell.interactions`. Layout Builder stores block interactions under `meta.interactions`.

`PresentationSettingsSchema` remains responsible for presentation and delivery settings. Advanced presentation controls are permission-gated by `presentation.manage_advanced`; interaction controls stay editor-facing while target presentation settings remain nested under the advanced presentation schema where applicable.

For editors, keep the first layer simple:

- choose a preset or basic layout settings;
- add a clear trigger label and icon;
- choose what opens;
- choose how it opens.

Keep lower-level delivery controls behind the advanced permission. Normal editors should not need to understand encrypted references, route reservations, loading strategies, or runtime resource groups to add a video modal.

## Public Runtime

`resources/js/widget-runtime.js` owns interaction activation. It:

- loads only server-registered widget resources selected by generated public IDs;
- shares promise-backed loading, ready, and failed states by canonical URL;
- fetches V2 lazy widget targets only after click or keyboard activation while preserving ordinary no-JavaScript links;
- opens labelled modal and slide-over shells with a nested overlay stack, focus containment, and focus restoration;
- handles inline reveal and replace-region behaviours;
- activates nested widgets, nested fragments, and nested interactions inside fetched HTML;
- keeps fetched HTML usable if an optional resource fails and exposes intentional resource retry through `window.CapellWidgetRuntime.retryResources()`;
- renders contextual interaction failure UI with Retry and a validated fallback link.

Keep this runtime generic. Package-specific widget JavaScript belongs in registered resource groups.

## Safe Defaults And Failure Modes

| Situation                                                          | Behaviour                                                                                             |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Existing content has no presentation metadata                      | It renders server-side with system defaults.                                                          |
| A trigger is missing required target data                          | The resolver drops the trigger.                                                                       |
| A lazy widget reference is invalid                                 | The endpoint returns a generic failure.                                                               |
| A fragment reference is invalid or replayed for another page scope | The endpoint returns a generic 404.                                                                   |
| Rendered lazy HTML contains unsafe authoring surface               | The endpoint blocks the response.                                                                     |
| A lazy fetch fails in the browser                                  | The runtime does not expose diagnostics in public HTML and may use a safe fallback URL if configured. |
