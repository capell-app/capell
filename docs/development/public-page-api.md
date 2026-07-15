# Public Page API Internals


Capell public page data is resolved in Core so frontend rendering and API delivery do not drift.

## Page Resolution

Use `Capell\Core\Actions\ResolvePublicPageByUrlAction` to resolve a published page for a site, language, and URL.

The action returns `Capell\Core\Data\PublicPageResolutionData`:

- `page`
- `site`
- `language`
- `layout`
- `fields`

`fields` contains the public page projection:

- `url`
- `title`
- `content`
- `meta`

The resolver applies public constraints: matching site and language URL, enabled non-redirect URL, accessible enabled type, publish dates, and a translation for the requested language. Revision IDs are only accepted when they belong to the same base page identity as the resolved URL.

## Layout Graph

Use `Capell\LayoutBuilder\Actions\BuildPublicLayoutGraphAction` to extract [Content Sections](../packages/content-sections.md) content without depending on the frontend package.

```php
$graph = BuildPublicLayoutGraphAction::run(
    layout: $layout,
    page: $page,
    language: $language,
    containers: ['main'],
    includeHtml: false,
);
```

Empty containers or `['*']` returns every layout container. Named containers return only those container keys.

Container filters are applied before element asset preload so `['main']` does not load unrelated sidebar/footer element assets.

## Layout Areas

The public page body renders only Content Sections containers in the `main` area. Missing `meta.area` values are treated as `main` for compatibility with older layouts.

Theme chrome can render named areas explicitly. Optional chrome or Layout Builder packages can register and render `header`:

```blade
<x-capell::layout.area area="header" />
```

Area rendering uses the already-resolved layout containers and stored layout manager elements. Public Blade should not query for layouts, elements, pages, or media to render an area, and it must not expose authoring metadata.

## Element Payloads

Element payloads are resolved through `Capell\LayoutBuilder\Contracts\PublicLayoutWidgetPayloadResolver`.

The default resolver returns:

- `title`
- `content`

and returns `null` for HTML.

Packages may contribute payloads through `Capell\LayoutBuilder\Contracts\PublicLayoutWidgetPayloadContributor::TAG` (`capell.layout_builder.public_layout_widget_payload_contributor`) when they need rendered element HTML or a richer public data shape. Keep contributors public-output focused; do not expose admin/editor-only metadata, raw model ids, or private asset references unless the package deliberately defines them as public API fields.

## Next

- [Development](index.md)
- [Frontend guide](../frontend/guide.md)
