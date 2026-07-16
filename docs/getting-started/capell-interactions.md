# Capell Interactions

![Capell Interactions screenshot](../images/capell-readme-banner.jpg)

Capell Interactions lets an editor attach a public action to a widget or [Layout Builder](https://docs.capell.app/packages/layout-builder) block. The action can open another widget in a modal, slide in a form, reveal inline content, replace a region, or fetch a Layout Builder fragment only when the visitor asks for it.

The target is not a separate mini-app. It is a normal Capell widget or public Layout Builder block rendered through the same typed, tested rendering pipeline as the rest of the page. That is the point of the feature: rich interactive pages without one-off theme JavaScript, hidden IDs in HTML, or bespoke controller routes for every campaign.

## The Mental Model

Think of every interaction as three pieces:

| Piece     | What it means                                     | Example                                                            |
| --------- | ------------------------------------------------- | ------------------------------------------------------------------ |
| Trigger   | The public control the visitor can use            | `Play video` button                                                |
| Behaviour | How the target appears                            | Modal, slide-over, inline reveal, replace region                   |
| Target    | The content Capell renders after the trigger runs | Video widget, form widget, gallery widget, Layout Builder fragment |

Editors configure those pieces in the admin. Developers register the widget types, resources, defaults, and safe render boundaries. Visitors only receive the trigger plus an encrypted URL for the lazy target. They do not receive widget data, block keys, model IDs, component names, package names, field paths, or editor metadata.

## Why It Matters

Most CMS builds split interactive content into one-off JavaScript, theme-specific modals, hidden page fields, or custom controller endpoints. That works until the second theme, the third campaign page, or the first performance audit.

Capell gives interactions a shared model:

- editors add the trigger and configure the target content without leaving the normal content flow;
- target content renders through the same widget or Layout Builder block pipeline as ordinary page content;
- expensive experiences can stay lazy until the visitor clicks or reveals them;
- modal, slide-over, inline reveal, and replace-region behaviours are handled by the frontend runtime;
- encrypted opaque references keep internal IDs, component names, package names, field paths, and editor metadata out of public HTML.

That makes interactive pages easier to sell, build, cache, and maintain.

## What Editors Can Build

| Interaction       | Visitor experience                                | Capell target                        |
| ----------------- | ------------------------------------------------- | ------------------------------------ |
| Play video        | Opens a video in a modal                          | Video widget                         |
| Book demo         | Opens a form in a slide-over                      | Form widget                          |
| Compare plans     | Replaces a pricing teaser with a comparison view  | Widget target                        |
| Show gallery      | Opens a carousel without loading it upfront       | Widget target with runtime resources |
| Reveal details    | Expands technical content below the button        | Inline widget                        |
| Load more context | Fetches a Layout Builder block fragment on demand | Lazy fragment                        |

The result feels like a lightweight interaction builder, but the implementation stays Laravel-native and package-safe.

## How It Renders

Most page content stays server-rendered. Interactions only change the target content that should wait until the visitor asks for it.

1. The public page renders the visible widget or block.
2. Capell renders a safe trigger, for example a `Play video` button.
3. The trigger points at an encrypted lazy endpoint.
4. When the visitor uses the trigger, `resources/js/widget-runtime.js` fetches the target.
5. The fetched HTML is mounted as a modal, slide-over, inline reveal, or replacement region.
6. Nested widgets, resources, fragments, and interactions are activated inside the fetched HTML.

There are two lazy endpoints:

| Endpoint                         | Renders                               | Used for                                                       |
| -------------------------------- | ------------------------------------- | -------------------------------------------------------------- |
| `/_capell/widgets/{reference}`   | Registered widget targets             | Video modals, forms, galleries, calculators, comparison panels |
| `/_capell/fragments/{reference}` | Layout Builder public block fragments | Expensive or optional block content                            |

Both references are encrypted JSON. Both endpoints fail generically when a reference is invalid. Public pages never use plain route parameters such as page IDs, block keys, widget keys, or component names.

## Presentation And Delivery

Presentation settings decide how a widget or block behaves before any interaction runs. The default is still ordinary server rendering, so existing content does not change after deployment.

Settings resolve in this order:

1. instance override;
2. type default;
3. presentation preset default;
4. system default.

Normal editors see the simple controls: preset, component choice where relevant, width, alignment, and display behaviour. Advanced delivery controls, including lazy fragment delivery, loading strategy, connection requirement, viewport range, and custom width, are hidden unless the user has `presentation.manage_advanced`.

## Built For Performance

Interactions are designed around lazy delivery. The public page can render the primary content first and leave heavier targets behind encrypted references until the visitor actually needs them.

Capell supports two lazy target surfaces:

- `/_capell/widgets/{reference}` renders registered widget targets from encrypted widget references.
- `/_capell/fragments/{reference}` renders Layout Builder public block fragments from encrypted block references.

Both routes return generic failures when a reference is invalid. Fragment references are revalidated against site, page, layout, language, container, block, and occurrence before rendering.

## Built For Safe Public Output

Capell’s public-output rule still applies: anonymous HTML must not reveal the editor exists.

Interaction placeholders and triggers do not expose model IDs, block keys, field paths, component names, package namespaces, signed URLs, widget data, or editor metadata. Target HTML is checked with the same public HTML safety inspection used for normal page rendering.

That keeps static HTML cache, crawlers, CDN output, and signed-in non-admin views safe.

## Why It Is A Selling Point

Interactions make Capell pages feel more like product experiences without turning every campaign into a custom frontend build. A team can ship richer pages with:

- fewer one-off theme scripts;
- fewer bespoke modal implementations;
- cleaner performance defaults;
- reusable widget targets across packages and themes;
- a safer public cache story;
- admin controls that stay simple for normal editors and deeper for advanced users.

For Laravel teams, the value is not just that a button can open a modal. The value is that interactive content uses the same typed, tested, package-owned system as the rest of the CMS.

## Who Should Read What

| Reader                                                       | Start here                                                                                                 |
| ------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- |
| Editor or demo audience                                      | This page                                                                                                  |
| Theme/package developer adding widgets                       | [Frontend widgets](../frontend/widgets.md)                                                                 |
| Developer wiring resources, presentation, and lazy endpoints | [Presentation delivery](../../packages/frontend/docs/presentation-delivery.md)                             |
| Admin developer reusing Filament schema helpers              | [Presentation and interactions admin controls](../../packages/admin/docs/presentation-and-interactions.md) |
| Layout Builder package maintainer                            | [Layout Builder package docs](https://docs.capell.app/packages/layout-builder)                             |

## Next

- [Frontend widgets](../frontend/widgets.md)
- [Frontend guide](../frontend/guide.md)
- [Presentation delivery](../../packages/frontend/docs/presentation-delivery.md)
- [Frontend extensions](../packages/frontend-extensions.md)
- [Public HTML safety](../frontend/public-html-safety.md)
