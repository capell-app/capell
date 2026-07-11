# Inertia Widgets

![Capell Inertia Widgets screenshot](../images/capell-readme-banner.jpg)

Inertia widgets use the same Capell widget registry as Blade and Livewire widgets. The difference is the target: an Inertia widget registers a server component name that the active Vue or React adapter can resolve.

## Register An Inertia Widget

Use `LayoutWidgetDefinitionData::frontendInertia()` for new package widgets:

```php
use Capell\LayoutBuilder\Data\LayoutWidgets\LayoutWidgetDefinitionData;
use Capell\LayoutBuilder\Support\LayoutWidgets\LayoutWidgetRegistry;

public function boot(LayoutWidgetRegistry $widgets): void
{
    $widgets->registerDefinition(LayoutWidgetDefinitionData::frontendInertia(
        key: 'booking-card',
        component: 'Capell/Widgets/BookingCard',
        resourceGroups: ['example.booking-card'],
        defaultPresentationSettings: [
            'loading_strategy' => 'visible',
        ],
    ));
}
```

`component` is the server component name sent to the Inertia client payload. It should match a component registered by the active Vue or React package. Treat it as a public client contract: use stable names such as `Capell/Widgets/BookingCard`, not PHP class names, vendor package slugs, database identifiers, or admin-only labels.

Core Frontend registers these default Inertia widgets:

| Widget key | Component                |
| ---------- | ------------------------ |
| `content`  | `Capell/Widgets/Content` |
| `image`    | `Capell/Widgets/Image`   |
| `title`    | `Capell/Widgets/Title`   |

## Payload Shape

The API package owns the public layout payload. When `PublicPagePayloadOptionsData::$includeWidgetComponents` is `true`, `BuildPublicLayoutPayloadAction` looks up each widget type against `LayoutWidgetTarget::FrontendInertia` and adds a `component` key when one is registered.

That option is used by `BuildInertiaPagePropsAction`. It is not set by the public page resolve API, so the existing `/api/capell/v1/pages/resolve` contract stays unchanged.

Inertia widget payloads still use the normal public widget data shape:

```php
[
    'key' => 'hero-1',
    'type' => 'booking-card',
    'component' => 'Capell/Widgets/BookingCard',
    'data' => [
        'heading' => 'Book a consultation',
        'summary' => 'Choose a service and request a time.',
    ],
]
```

Keep widget `data` limited to display props produced by the public layout widget payload resolver. Do not add editor metadata, target widget definitions, internal model identifiers, or package-only state to Inertia widget props.

## Lazy Loading

Use the loading surface that matches the work being deferred:

| Need                                                        | Use                                                                                          |
| ----------------------------------------------------------- | -------------------------------------------------------------------------------------------- |
| Defer page-level or package-level Inertia props             | Inertia optional/deferred props and partial reloads.                                         |
| Defer a visitor-triggered widget or Layout Builder fragment | Capell lazy widget and fragment endpoints.                                                   |
| Defer widget CSS or JavaScript                              | `FrontendResourceRegistry` resource groups with `visible`, `interaction`, or `idle` loading. |

For Inertia page components, prefer optional props when the data is expensive and tied to a client selection. The Bookings request flow uses optional slot props so the shell can load first and request `slots` through a partial reload.

Capell lazy widget targets still render through `/_capell/widgets/{reference}` with encrypted references. Keep using that endpoint for modal, slide-over, inline reveal, and replace-region targets that should only exist after visitor interaction.

## Public Output Rules

Inertia widget payloads must not expose:

- admin/editor controls;
- model IDs;
- field paths;
- block keys;
- package namespaces;
- signed URLs;
- raw lazy target payloads.

Use [Frontend widgets](../frontend/widgets.md), [Frontend extensions](../packages/frontend-extensions.md), and [Capell Interactions](capell-interactions.md) when combining Inertia widgets with lazy targets or runtime resources.
