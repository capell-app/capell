# Admin Domain And Path

![Capell sites list](../images/generated/admin/admin-sites-list.png)

Capell Admin should be treated as deployment configuration, not content. Change the admin entrypoint in `config/capell-admin.php` or with environment variables so every environment can use its own URL without writing anything to the database.

## Defaults

By default, Capell Admin is served at:

```text
https://example.com/admin
```

The default config is:

```php
return [
    'path' => env('CAPELL_ADMIN_PATH', 'admin'),
    'domain' => env('CAPELL_ADMIN_DOMAIN'),
];
```

`CAPELL_ADMIN_PATH` controls the Filament panel path and Capell admin-owned package routes such as `api/page-tree`. `CAPELL_ADMIN_DOMAIN` optionally restricts the admin panel and admin-owned package routes to one host.

Site records remain content/runtime configuration. They should carry public domains and site relationships, while the admin path and admin host stay in environment configuration.

![Capell site edit form](../images/generated/admin/admin-site-edit-form.png)

## Change `/admin` To Another Path

Set `CAPELL_ADMIN_PATH` to the path segment you want:

```env
CAPELL_ADMIN_PATH=cms
CAPELL_ADMIN_DOMAIN=
```

The admin URL becomes:

```text
https://example.com/cms
```

Do not include a scheme or host in `CAPELL_ADMIN_PATH`. Capell trims surrounding slashes, so `cms`, `/cms`, and `/cms/` resolve to the same path.

## Serve Admin From A Subdomain

Point DNS and your web server at the same Laravel application, then configure the admin domain:

```env
CAPELL_ADMIN_DOMAIN=admin.example.com
CAPELL_ADMIN_PATH=/
```

The admin URL becomes:

```text
https://admin.example.com
```

`CAPELL_ADMIN_DOMAIN` must be only the hostname. Use `admin.example.com`, not `https://admin.example.com`.

If you prefer the admin to remain below a path on the subdomain, keep a path value:

```env
CAPELL_ADMIN_DOMAIN=admin.example.com
CAPELL_ADMIN_PATH=panel
```

The admin URL becomes:

```text
https://admin.example.com/panel
```

## Manual Panel Providers

If your app owns `app/Providers/Filament/AdminPanelProvider.php`, use the shared entrypoint helper instead of hard-coded strings:

```php
use Capell\Admin\Support\AdminPanelEntrypoint;
use Filament\Panel;

public function panel(Panel $panel): Panel
{
    return $panel
        ->id('admin')
        ->domain(AdminPanelEntrypoint::domain())
        ->path(AdminPanelEntrypoint::path());
}
```

Keep the rest of the Capell panel integration in place: colors, navigation, `CapellAdminPlugin`, widgets, middleware, and auth middleware still belong in the panel provider.

## After Changing The Entrypoint

Clear cached config and routes after changing the environment:

```sh
php artisan optimize:clear
```

Then verify the new URL in a browser and update any operational links, reverse proxy rules, uptime checks, password manager entries, and deployment documentation that still point at `/admin`.
