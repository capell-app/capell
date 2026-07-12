# Frontend Widgets

Frontend widgets are registered public components that Capell can render in normal content, lazy interaction targets, and package-owned experiences. A widget definition answers four questions:

| Question                                                         | Defined by                                                     |
| ---------------------------------------------------------------- | -------------------------------------------------------------- |
| Which widget key can editors choose?                             | `LayoutWidgetDefinitionData::$key`                             |
| Which Blade/Livewire component renders it?                       | `LayoutWidgetDefinitionData::$component` and target            |
| Which frontend assets does it need?                              | `resourceGroups`                                               |
| How should it present, load, and expose interactions by default? | `defaultPresentationSettings` and `defaultInteractionTriggers` |

[Content Sections](../packages/content-sections.md) and [Layout Builder](../packages/layout-builder.md) use this same surface for editor-managed content. The registry lives in the core/frontend boundary so packages can ship reusable widget targets without inventing their own modal systems, asset loaders, or public routes.

## In This Section

| Task                                          | Read                                                     |
| --------------------------------------------- | -------------------------------------------------------- |
| Register a widget and load its CSS/JS         | [Widget registration](widget-registration.md)            |
| Set instance, presentation, and loading state | [Widget state](widget-state.md)                          |
| Open a widget or fragment from an interaction | [Widget and fragment targets](widget-targets.md)         |
| Register Inertia widgets                      | [Inertia widgets](../getting-started/inertia-widgets.md) |

Widget HTML and interaction placeholders must never expose admin controls, model IDs, field paths, block keys, component names, package namespaces, signed URLs, or raw target widget data. [Widget and fragment targets](widget-targets.md#public-output-rules) lists the full rule.

When a widget defers below-the-fold HTML, prepare a `DeferredFragmentPlaceholderData` value in PHP and render only that value in Blade. Use `DeferredFragmentReference` for encrypted reference payloads and stable cache keys; route names and authorization checks remain owned by the application or package that serves the fragment.

## Next

- [Frontend](index.md)
- [Widget registration](widget-registration.md)
- [Widget state](widget-state.md)
- [Widget and fragment targets](widget-targets.md)
