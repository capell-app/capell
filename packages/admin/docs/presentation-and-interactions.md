# Presentation And Interactions Admin Controls

![Capell Presentation And Interactions Admin Controls screenshot](./images/screenshots/admin-dashboard.png)

Capell Admin exposes shared Filament schema helpers for presentation settings and interaction triggers. Use these helpers whenever a Content Builder widget, Layout Builder block, or type-default form needs public delivery controls.

The goal is a progressive editor experience:

- normal editors see presets, width/alignment, display choices, trigger labels, icons, targets, and behaviours;
- advanced delivery controls stay collapsed and permission-gated;
- stored state uses the same paths across Content Builder, Layout Builder, and package type defaults;
- public rendering can strip editor metadata before any widget component receives props.

## Presentation Settings

Use `Capell\Admin\Filament\Components\Forms\Presentation\PresentationSettingsSchema` when a form needs presentation and delivery controls.

Storage paths:

| Surface                           | Path                                                       |
| --------------------------------- | ---------------------------------------------------------- |
| Content widget instance           | `data.__capell.presentation`                               |
| Widget type default               | `LayoutWidgetDefinitionData::$defaultPresentationSettings` |
| Layout Builder block instance     | layout block `meta.presentation`                           |
| Layout Builder block type default | type `meta.presentation`                                   |

The basic section should answer questions an editor understands:

| Editor question                                     | Control                              |
| --------------------------------------------------- | ------------------------------------ |
| Which common design/delivery setup should this use? | Presentation preset                  |
| How wide should it be?                              | Width mode                           |
| How should it align in the layout?                  | Alignment                            |
| Should it show normally across devices?             | Basic display controls where present |

The advanced section contains delivery mode, loading strategy, device visibility, connection requirement, custom viewport range, and custom width.

Advanced controls require the `presentation.manage_advanced` permission. That permission is granted to `super_admin` during install/upgrade sync and can be assigned to other roles.

Keep low-level settings in the advanced section. Normal editors should not need to understand lazy fragment delivery, network connection rules, or resource loading strategies to place content.

## Interaction Settings

Use `Capell\Admin\Filament\Components\Forms\Interactions\InteractionSettingsSchema` when a form needs public trigger controls.

Storage paths:

| Surface                           | Path                                                      |
| --------------------------------- | --------------------------------------------------------- |
| Content widget instance           | `data.__capell.interactions`                              |
| Widget type default               | `LayoutWidgetDefinitionData::$defaultInteractionTriggers` |
| Layout Builder block instance     | layout block `meta.interactions`                          |
| Layout Builder block type default | type `meta.interactions`                                  |

Editors can add a label, icon, style, target, and behaviour. Widget targets use the normal Capell widget builder nested inside the interaction form, so a video modal, gallery, form, or calculator target is still edited as a real widget.

Use this language when explaining the form:

| Field group        | Plain explanation                                                    |
| ------------------ | -------------------------------------------------------------------- |
| Trigger            | The button or link the visitor sees.                                 |
| Target             | What opens when the visitor uses the trigger.                        |
| Behaviour          | Where the target appears: modal, slide-over, inline, or replacement. |
| Target widget      | The widget Capell renders later through the lazy widget endpoint.    |
| Fragment reference | The encrypted public Layout Builder fragment to load later.          |

Supported target types:

- `widget`
- `fragment`
- `url`
- `public_action`

Supported lazy target behaviours:

- `modal`
- `slide_over`
- `inline_reveal`
- `replace_region`

## Recommended Editor Flow

For a common video-modal interaction:

1. Add or edit the visible widget/block.
2. Open **Interactions**.
3. Add a trigger with a label such as `Watch tour`.
4. Choose the play icon and primary style.
5. Set target type to `widget`.
6. Set behaviour to `modal`.
7. Add a `video-player` widget as the target widget.
8. Leave advanced presentation settings alone unless the target needs specific width or loading behaviour.

For a lazy Layout Builder fragment:

1. Add an interaction to the block that should expose the trigger.
2. Set target type to `fragment`.
3. Use `inline_reveal`, `modal`, `slide_over`, or `replace_region` depending on the experience.
4. Leave `fragment_reference` empty when the current Layout Builder block should be the lazy target.
5. Use advanced presentation settings only when the block itself should render as a lazy placeholder.

Layout Builder previews may show admin-only interaction chips such as `Watch tour -> widget`. Those chips are for editor clarity and are not rendered to the public page.

## Public Safety Boundary

Admin forms may store widget targets, presentation settings, and interaction metadata. Public rendering must strip editor state before components receive props.

Public triggers may expose labels, generic data attributes, and encrypted target URLs. They must not expose target widget data, widget keys, block keys, component names, package names, model IDs, field paths, permissions, signed URLs, or editor metadata.

Use the frontend safety tests when adding a new field to either schema. If the field is only needed by admin/editor workflows, it should not appear in rendered public HTML.

## When Adding New Fields

Before adding a field to either schema, decide which side needs it:

| Needed by                                      | Put it where                              |
| ---------------------------------------------- | ----------------------------------------- |
| Public rendering wrapper                       | Presentation metadata                     |
| Interaction trigger rendering                  | Interaction metadata                      |
| Widget component content                       | Normal widget `data`                      |
| Admin-only explanation, labels, or diagnostics | Admin schema only; do not render publicly |

Add tests for the resolver or public renderer whenever a new field can affect rendered HTML, lazy endpoints, or public asset loading.
