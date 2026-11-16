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

For a site with a **Service** post type, a `price` custom field and posts at addresses such as `/services/logo-design/`:

1. Create the page type and field by following [Build your first page type](build-your-first-page-type.md). That guide seeds a **Brand Sprint** service page at `/services/brand-sprint`. Do not import a post that has the same address, because the page you already have serves it and no redirect is made.
2. To keep old addresses, install URL Manager before you import. It is a paid package, so you need the Composer repository and credential described in [Paid package access](install.md#paid-package-access) before `composer require` can find it. Require WordPress Importer in the same step, because it is paid too. Without URL Manager the import still succeeds, but it creates no redirects and old addresses return 404:

   ```bash
   composer require capell-app/url-manager capell-app/wordpress-importer
   php artisan capell:package-cache:clear
   php artisan capell:extension-install capell-app/url-manager
   ```

   The install step also activates WordPress Importer and Migration Assistant, which it requires.
3. Create one page per post in Admin, or import the WXR export with [WordPress Importer](../packages/README.md). Run it first without `--execute` to preview, then with the target IDs. `--type-id` must be a blueprint of type `page`: use the ID of the **Service** blueprint from the previous guide. On a new starter project it is `10`; print yours with this command:

   ```bash
   php artisan tinker --execute='echo Capell\Core\Models\Blueprint::where("key", "service")->value("id");'
   php artisan wordpress-importer:import site.xml
   php artisan wordpress-importer:import site.xml --execute --site-id=1 --layout-id=1 --type-id=10 --actor-id=1
   ```

   Imported pages get an address from their title, so `/services/logo-design/` becomes `/logo-design`, and URL Manager adds a 301 from the old address.
4. The import does not copy custom fields. Open each imported service in Admin and enter its **Price**, because the public page shows an empty price until you do. The starter layout shows each page's title and summary, so add the imported body text to the layout when you convert it.
5. Replace each shortcode with a widget or Blade component.
6. Request each old address anonymously and check it redirects to the new page:

   ```bash
   curl -sI http://localhost:8000/services/logo-design/
   ```

## Related guides

- [Build a client site](build-a-client-site.md)
- [Comparing Capell](comparing-capell.md)
