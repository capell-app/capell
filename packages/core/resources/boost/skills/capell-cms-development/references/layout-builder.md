# Capell LayoutBuilder Reference

## Contents

- [Package Names](#package-names)
- [Tables And Columns](#tables-and-columns)
- [Container Shape](#container-shape)
- [Creating A Widget](#creating-a-widget)
- [Attaching A Widget To A Layout](#attaching-a-widget-to-a-layout)
- [Widget Assets](#widget-assets)
- [Sections](#sections)
- [Frontend Rendering Path](#frontend-rendering-path)
- [Block Library Fallback](#block-library-fallback)

## Package Names

Canonical package: `capell-app/layout-builder`.

Use the `Capell\LayoutBuilder\...` namespaces.

## Tables And Columns

LayoutBuilder adds:

| Table / column       | Purpose                                                                                                                                                                                                                                                 |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `widgets`            | Reusable widget instances. Key columns: `workspace_id`, `name`, `blueprint_id`, `key`, `content`, `meta`, `component`, `view_file`, `status`.                                                                                                           |
| `widget_assets`      | Ordered content assets for widgets. Scopes by `widget_id`, optional `pageable`, `container`, `occurrence`, and polymorphic `asset`.                                                                                                                     |
| `sections`           | Reusable, nested content records from `capell-app/content-sections` (`Capell\ContentSections\Models\Section`), which LayoutBuilder suggests. Key columns: `workspace_id`, `name`, `blueprint_id`, `site_id`, `meta`, nested-set columns, visible dates. |
| `layouts.containers` | Ordered container map used by the frontend renderer. Each container owns layout meta and a `widgets` list.                                                                                                                                              |

## Container Shape

`Layout::$containers` is an associative array keyed by handles such as `hero`, `main`, `sidebar`, or `footer`.

```php
[
    'main' => [
        'meta' => ['width' => 'contained'],
        'widgets' => [
            ['widget_key' => 'hero-banner', 'occurrence' => 1],
        ],
    ],
]
```

Use stable container keys in seeders. If a layout is shared across many pages, prefer page-specific `widget_assets` over mutating the shared layout per page.

## Creating A Widget

Create a blueprint with `type = widget`, then create a widget with a unique `key`.

```php
use Capell\Core\Models\Blueprint;
use Capell\LayoutBuilder\Models\Widget;

$blueprint = Blueprint::query()->firstOrCreate(
    ['type' => 'widget', 'key' => 'feature-grid'],
    ['name' => 'Feature Grid', 'group' => 'marketing', 'status' => true],
);

$widget = Widget::query()->updateOrCreate(
    ['key' => 'feature-grid', 'workspace_id' => 0],
    [
        'name' => 'Feature Grid',
        'blueprint_id' => $blueprint->id,
        'component' => 'widgets.feature-grid',
        'status' => true,
    ],
);
```

For Livewire widgets, set `is_livewire` and point `component` at the Livewire component alias/class expected by the package.

## Attaching A Widget To A Layout

Prefer the action so occurrence is calculated consistently.

```php
use Capell\LayoutBuilder\Actions\AddWidgetToLayoutContainerAction;

AddWidgetToLayoutContainerAction::run(
    widget: $widget,
    layout: $layout,
    container: 'main',
    skipExists: true,
);
```

The action requires `layouts.containers[$container]['widgets']` to exist. Seed the container before attaching.

## Widget Assets

Use `widget_assets` when the widget needs selectable content such as sections, pages, media, or content blocks.

```php
use Capell\LayoutBuilder\Models\WidgetAsset;

WidgetAsset::query()->updateOrCreate(
    [
        'widget_id' => $widget->id,
        'pageable_type' => $page->getMorphClass(),
        'pageable_id' => $page->id,
        'container' => 'main',
        'occurrence' => 1,
        'asset_type' => $section->getMorphClass(),
        'asset_id' => $section->id,
        'workspace_id' => 0,
    ],
    ['order' => 0, 'meta' => []],
);
```

Leave `pageable_*` null for global widget assets shared everywhere the widget appears.

## Sections

Sections are reusable content records and can be nested. Create a blueprint with `type = section`, then create a `Section` and its `Translation` rows.

## Frontend Rendering Path

1. Capell frontend resolves the current `Page` and `Layout`.
2. LayoutBuilder loops `layout->containers`.
3. Each container renders its widget references by `widget_key` and `occurrence`.
4. The widget resolves its component from its `component` column, then `meta.component`, then the blueprint's `component`, then the blueprint's `meta.component`, then the package default.
5. Widget assets are resolved by `widget_id`, `pageable`, `container`, and `occurrence`.

When debugging missing content, check page layout, container key, widget key, occurrence, widget status, component view existence, then page-specific `widget_assets`.

## Block Library Fallback

When LayoutBuilder is absent, use `capell-app/block-library` for reusable content blocks. It stores reusable content records and can integrate with LayoutBuilder later. Do not invent a parallel widget table in the app.
