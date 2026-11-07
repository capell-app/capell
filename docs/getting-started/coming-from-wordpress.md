# Coming from WordPress

Capell is a Laravel application you own, so most WordPress ideas map to Laravel code plus editor screens in Admin.

| WordPress | Capell | Where it lives |
| --- | --- | --- |
| Page or post | Page | **Pages** in Admin |
| Post type | Page type (blueprint) | [Build your first page type](build-your-first-page-type.md) |
| Custom field | Editor field on a configurator, stored in `meta` | `ServicePageConfigurator` in that guide |
| Block | Widget on a layout | [Widget registration](../frontend/widget-registration.md) |
| Shortcode | Widget or Blade component | Public Blade receives hydrated data |
| Theme and `functions.php` | Theme views plus Laravel providers | `app/Providers`, `resources/views/capell` |
| Plugin | Composer package | [Packages](../packages/README.md) |
| `wp_query` in a template | Render data contributor | Templates never query models |

## Convert a sample site

For a site with a **Service** post type and a `price` custom field:

1. Create the page type and field by following [Build your first page type](build-your-first-page-type.md).
2. Create one page per post in Admin, or preview the WXR export with the paid [WordPress Importer](../packages/README.md), which keeps permalinks, taxonomy, authors, media references and shortcodes as metadata for mapping.
3. Replace each shortcode with a widget or Blade component.
4. Keep old addresses as page URLs or redirects, then check each one anonymously.

## Next

- [Build a client site](build-a-client-site.md)
- [Comparing Capell](comparing-capell.md)
