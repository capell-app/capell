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
2. To keep old addresses, install URL Manager before you import. It is a paid package, so you need the Composer credential described in [Paid package access](install.md#paid-package-access) before `composer require` can find it. Without it the import still succeeds, but it creates no redirects and old addresses return 404:

   ```bash
   composer require capell-app/url-manager
   php artisan capell:package-cache:clear
   php artisan capell:extension-install capell-app/url-manager
   ```

3. Create one page per post in Admin, or import the WXR export with the paid [WordPress Importer](../packages/README.md). Run it first without `--execute` to preview, then with the target IDs. `--type-id` must be a blueprint of type `page`:

   ```bash
   php artisan wordpress-importer:import site.xml
   php artisan wordpress-importer:import site.xml --execute --site-id=1 --layout-id=1 --type-id=2 --actor-id=1
   ```

   Imported pages get an address from their title, so `/services/brand-sprint/` becomes `/brand-sprint`, and URL Manager adds a 301 from the old address.
4. Replace each shortcode with a widget or Blade component.
5. Request each old address anonymously and check it redirects to the new page.

## Next

- [Build a client site](build-a-client-site.md)
- [Comparing Capell](comparing-capell.md)
