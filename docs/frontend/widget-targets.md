# Widget And Fragment Targets

A widget can expose interaction triggers that open another experience when the visitor acts. Lazy targets render through opaque public URLs provided by companion packages, so the page never ships the target content or its internals up front.

## Interaction Targets

Widgets can expose interaction triggers through `data.__capell.interactions` or type defaults in `LayoutWidgetDefinitionData::$defaultInteractionTriggers`.

Supported target types:

| Target          | Use                                                                     |
| --------------- | ----------------------------------------------------------------------- |
| `widget`        | Render a registered widget through a companion lazy widget endpoint.    |
| `fragment`      | Render a Layout Builder block fragment through a companion endpoint.    |
| `url`           | Link to a safe URL.                                                     |
| `public_action` | Use a safe fallback URL unless a package renders the action elsewhere.  |

Supported behaviours for lazy targets are `modal`, `slide_over`, `inline_reveal`, and `replace_region`.

Capell Frontend does not ship the public endpoints for lazy targets. Each lazy target type activates when a companion package registers its contract in the container:

- Widget targets activate when a package binds `Capell\Frontend\Contracts\WidgetInteractionLocatorResolver`, which returns the public URL for the target widget.
- Fragment targets activate when at least one package tags a `Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver`. Each resolver owns one stable identifier and only generates its own named public route.

While the matching contract is unbound, triggers for that target type are safely omitted from public output, and the Admin interaction schema does not offer the fragment target. The rendered trigger contains only the opaque URL the bound contract returns; it does not expose the widget type, component name, package name, target content, model IDs, field paths, or editor metadata.

Use a widget target when the visitor is opening a separate experience, such as a video player, form, gallery, quote calculator, or comparison panel. Use a Layout Builder fragment target when the visitor is loading a public block fragment from the current layout.

## Example: Button Opens A Video Widget

The editor-facing shape for a trigger that opens a video in a modal looks like this:

```php
[
    'label' => 'Watch tour',
    'icon' => 'heroicon-o-play-circle',
    'style' => 'primary',
    'target_type' => 'widget',
    'behavior' => 'modal',
    'modal_size' => 'lg',
    'target_widget' => [
        [
            'type' => 'video-player',
            'data' => [
                'title' => 'Product tour',
                'video_url' => 'https://example.com/product-tour.mp4',
            ],
        ],
    ],
]
```

The public page renders a safe trigger and an opaque lazy widget URL. It does not render the target widget content until the visitor clicks.

Fragment targets follow the same rules. The stored `fragment_reference` is an encrypted, versioned envelope; Frontend decodes it and asks the resolver registered for its exact owner to generate the public URL. Unknown owners and invalid envelopes are omitted from public output.

## Public Fragment Envelope

Every public fragment reference contains these fields:

| Field | Meaning |
| --- | --- |
| `owner` | Stable resolver identifier, such as `layout-builder` or `marketing`. |
| `formatVersion` | Shared protocol version. Unsupported versions are rejected. |
| `pageableType`, `pageableId` | Morph identity of the authoritative public page. |
| `siteId`, `languageId` | Public site and language context. |
| `contentVersion` | Deterministic version of the current public render inputs. |
| `ownerContext` | Scalar-only identifiers interpreted by the declared owner. |

Before rendering, an endpoint must decode the envelope, assert its owner, run `ResolvePublicFragmentContextAction`, validate its owner-specific context, inspect the final HTML for authoring-surface leakage, and only then add public cache headers. Draft, scheduled, expired, deleted, inaccessible, stale, and cross-context references all return the same generic 404 response.

## Public Output Rules

Widget HTML and interaction placeholders must not expose:

- admin/editor controls;
- model IDs;
- field paths;
- block keys;
- component names;
- package namespaces;
- signed URLs;
- raw target widget data.

Use [Public HTML safety](public-html-safety.md), [Presentation delivery](../../packages/frontend/docs/presentation-delivery.md), and [Frontend extensions](../packages/frontend-extensions.md) when changing widget rendering.

## Next

- [Frontend widgets](widgets.md)
- [Widget registration](widget-registration.md)
- [Widget state](widget-state.md)
- [Capell Interactions](../getting-started/capell-interactions.md)
