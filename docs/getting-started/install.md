# Capell install guide

This guide has two paths:

- **Path A:** a fresh Laravel app for a clean install.
- **Path B:** an existing Laravel app where you need to keep current users, routes, data, and deploy flow.

For the fastest demo, use the [quickstart](quickstart.md).

Most fresh apps should use the **guided browser installer**: install the packages, open `/install`, and let it create your admin user and apply the required changes. The numbered steps below document the same work for non-interactive CI installs, existing apps, and anyone who wants to apply each change by hand.

![Capell guided installer screen](../images/generated/package-surfaces/install-guide-page.png)

## Requirements

| Tool     | Supported versions                                                   |
| -------- | -------------------------------------------------------------------- |
| PHP      | 8.4+                                                                 |
| Laravel  | 12.41.1+ or 13.x                                                     |
| Filament | 5.6.8 (currently `^5.6.8 <5.7.0-beta`)                              |
| Database | MySQL 8+, MariaDB 10.3+, SQLite, or your configured Laravel database |
| Node.js  | 20+                                                                  |
| Composer | 2.7+                                                                 |

Required PHP extensions: `fileinfo`, `intl`, `mbstring`, `openssl`, `curl`, `simplexml`, and either `gd` or `imagick`.

## Install-time write permissions

If the installer, Composer, and Artisan commands run as the same user that owns
the application files, normal Laravel permissions are usually enough. On
servers where the deploy user and web/PHP-FPM user differ, make these paths
writable by the installing user and the web group before running
`php artisan capell:install`.

Required during install:

| Path                                            | Why Capell may write to it                                                                                                            |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `.env`                                          | Stores Capell frontend route settings when the installer removes or keeps the welcome route.                                          |
| `app/Models/User.php`                           | Adds Filament, roles, activity log, and Act as owner support-action integration when using installer patches.                         |
| `app/Providers/Filament/AdminPanelProvider.php` | Registers the Capell admin plugin and panel integration when using installer patches.                                                 |
| `config/filesystems.php`                        | Adds the `page_cache` disk.                                                                                                           |
| `config/logging.php`                            | Adds the `capell` log channel.                                                                                                        |
| `routes/web.php`                                | Removes Laravel's stock welcome route when Capell should own `/`.                                                                     |
| `resources/css/filament/admin/theme.css`        | Adds Capell Tailwind source paths.                                                                                                    |
| `database/migrations/`                          | Publishes Capell and selected package migrations.                                                                                     |
| `storage/`                                      | Stores installer logs, Laravel cache/session files when configured, Tailwind class output, backups, and normal Laravel runtime files. |
| `bootstrap/cache/`                              | Stores Laravel optimized caches and Capell package/theme cache files.                                                                 |
| `public/`                                       | Allows `php artisan storage:link` to create `public/storage` when it does not already exist.                                          |
| Configured static HTML output path              | Stores generated HTML when an installed cache/static package enables static output.                                                   |
| `public/vendor/capell-frontend/`                | Stores published Capell frontend assets.                                                                                              |
| `public/build/filament/`                        | Stores compiled Filament theme assets.                                                                                                |

Only add these when the installer will install extra packages or developer
tooling with Composer:

| Path            | Why Capell may write to it                          |
| --------------- | --------------------------------------------------- |
| `composer.json` | Adds selected Capell packages or developer tooling. |
| `composer.lock` | Records the resolved package set.                   |

Copy and adjust this for your server. Replace `APP_USER` with the deploy/app
owner and `WEB_GROUP` with the group used by PHP-FPM or the web server, for
example `www-data`, `nginx`, or a site-specific group.

```bash
APP_USER="replace-with-app-user"
WEB_GROUP="replace-with-web-group"

INSTALL_DIRS=(
  storage/framework/cache
  storage/framework/data
  storage/framework/sessions
  storage/framework/views
  storage/logs
  storage/capell
  bootstrap/cache
  database/migrations
  # Add the installed cache/static package output path here when enabled.
  public/vendor/capell-frontend
  public/build/filament
)

INSTALL_FILES=(
  .env
  app/Models/User.php
  app/Providers/Filament/AdminPanelProvider.php
  config/filesystems.php
  config/logging.php
  routes/web.php
  resources/css/filament/admin/theme.css
)

sudo mkdir -p "${INSTALL_DIRS[@]}"

for path in "${INSTALL_DIRS[@]}"; do
  sudo chown -R "$APP_USER:$WEB_GROUP" "$path"
  sudo chmod -R ug+rwX "$path"
done

for path in "${INSTALL_FILES[@]}"; do
  if [ -e "$path" ]; then
    sudo chown "$APP_USER:$WEB_GROUP" "$path"
    sudo chmod ug+rw "$path"
  else
    echo "Skipped missing install file: $path"
  fi
done

sudo chown "$APP_USER:$WEB_GROUP" public
sudo chmod ug+rwx public
sudo find "${INSTALL_DIRS[@]}" -type d -exec chmod g+s {} +
```

If the installer will run Composer package changes, also run:

```bash
APP_USER="replace-with-app-user"
WEB_GROUP="replace-with-web-group"

for path in composer.json composer.lock; do
  if [ -e "$path" ]; then
    sudo chown "$APP_USER:$WEB_GROUP" "$path"
    sudo chmod ug+rw "$path"
  else
    echo "Skipped missing Composer file: $path"
  fi
done
```

After install, production can be tightened back to your normal deployment model.
The app still needs Laravel runtime write access to `storage/`,
`bootstrap/cache/`, and any enabled static output path owned by an installed
cache package; source files like `app/`, `config/`, `routes/`, and `resources/`
do not need to remain writable by the web process.

## Path A: fresh Laravel app

There are two ways to pull in the Capell packages. Both end at the same `php artisan capell:install` step:

- **Recommended — the installer.** `composer require capell-app/installer` brings in core, and the guided `/install` flow (or `capell:install`) composer-requires the admin and frontend packages you select and removes the installer when you are done. Best for most fresh apps. Use this in step 3 below.
- **Manual — require packages directly.** Skip the installer and `composer require` the exact packages you want (`capell-app/core` is the only hard dependency; `admin` and `frontend` are optional). Best when you want Core without the admin or public frontend, or need to pin the package set yourself. See [Manual install](#manual-install-without-the-installer) at the end of step 3.

### 1. Create the app

```bash
composer create-project laravel/laravel music-store
cd music-store
cp .env.example .env
php artisan key:generate
```

Set your database values in `.env`, then confirm Laravel can boot:

```bash
php artisan about
```

### 2. Install the public foundation

Core, Admin, Frontend, Installer and Marketplace have public source repositories and public Packagist packages under the Capell licence. The next step can therefore require `capell-app/installer` directly without adding a custom Composer repository or authentication.

Paid marketplace packages use authenticated Composer access. After purchase or entitlement assignment, follow the Composer repository and bearer-token instructions shown in the Capell account for that customer organisation. Those credentials are scoped to protected packages and are not needed for the public foundation.

### 3. Install Capell and the setup package

```bash
composer require capell-app/installer
php artisan filament:install --panels
php artisan make:filament-theme
```

`capell-app/installer` is the bootstrap entrypoint: requiring it pulls in `capell-app/core`, and the guided `/install` flow composer-requires the admin and frontend packages you choose (default: all installable). It is removable after setup.

#### Manual install (without the installer)

Prefer to control the package set yourself? Skip `capell-app/installer` and require the packages directly. `capell-app/core` is the only hard dependency; `admin` and `frontend` are optional and each depend on core.

```bash
# Full stack, no installer
composer require capell-app/core capell-app/admin capell-app/frontend -W

# Core only, without Admin or Frontend
composer require capell-app/core -W

# Core + admin, no public frontend
composer require capell-app/core capell-app/admin -W
```

Then run the CLI installer (step 8) to apply migrations and setup. Scope it to what you installed with `--packages`, for example:

```bash
php artisan capell:install --packages=capell-app/admin
```

Without `--packages`, `capell:install` defaults to all installable packages and will composer-require any that are missing — which is exactly what the recommended installer flow relies on.

> **Recommended — run the guided installer.** Serve the app and open `/install`. The browser installer runs preflight checks, lets you choose packages, creates your first admin user, and applies the work in steps 4–8 for you: the `User` model traits, the Filament panel (plugin, navigation, colors, widgets, theme), the `page_cache` disk and `capell` log channel, the theme `@source` lines, and the welcome-route handover. Check the success report, then remove the installer from the success screen. A few items — queue worker, web-server cache rules, media backend — stay manual and are shown as guidance.
>
> Prefer to apply each change yourself, or installing through non-interactive CI? Continue with steps 4–9.

### 4. Prepare your admin user

Capell uses your Laravel `User` model for admin login. Edit `app/Models/User.php` so it implements Filament access and the traits Capell expects. The `HasImpersonation` trait powers the guarded Act as owner support action in the Users resource:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasImpersonation;
    use HasPanelShield;
    use HasRoles;
    use LogsActivity;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('user')
            ->logAll()
            ->logExcept(['email_verified_at', 'password', 'remember_token', 'updated_at', 'created_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
```

Your real model can keep its existing fillable, hidden, casts, notifications, and factory traits.

> When `capell-app/admin` is installed, `capell:install` applies these `User` traits for you (the `UserModelPatch`) and tells you if it could not. Edit by hand only when the installer reports a manual change, or when you skip the installer.

### 5. Register Capell in the Filament panel

Edit `app/Providers/Filament/AdminPanelProvider.php` and add the Capell plugin, navigation, colors, and widgets:

```php
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Admin\Support\AdminPanelEntrypoint;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->domain(AdminPanelEntrypoint::domain())
            ->path(AdminPanelEntrypoint::path())
            ->colors(FilamentColorEnum::colors())
            ->navigationItems(CapellAdmin::getNavigationItems())
            ->navigationGroups(CapellAdmin::getNavigationGroups())
            ->plugin(
                CapellAdminPlugin::make()
                    ->discoverConfigurators(in: app_path('Filament/FormBuilder'), for: 'App\\Filament\\FormBuilder')
            )
            ->widgets([
                ListPagesFilamentWidget::class,
                MyWorkQueueFilamentWidget::class,
                RecentlyPublishedFilamentWidget::class,
            ]);
    }
}
```

> The guided `/install` flow writes this panel configuration for you — plugin, navigation, colors, and widgets. On the CLI/manual path, add it by hand as shown above.

`CapellAdminPlugin` auto-registers Capell's required Filament plugins when they
are missing, including the welcome tour plugin. A consuming app does not need a
separate `FilamentTourPlugin::make()` call for the default admin tour.

The helper keeps the panel aligned with `CAPELL_ADMIN_PATH` and
`CAPELL_ADMIN_DOMAIN`. Use it if you want `/cms`, an admin subdomain, or a
subdomain root instead of `/admin`. See [Admin](../admin/index.md).

Install optional login auditing from the dedicated package by running `composer require capell-app/login-audit`, then `php artisan migrate`.

### 6. Configure storage, logging, and queues

If the installed cache/static package expects a dedicated disk, add it to `config/filesystems.php`. Older installs commonly use:

```php
'page_cache' => [
    'driver' => 'local',
    'root' => public_path('page-cache'),
    'throw' => false,
],
```

Add a Capell log channel to `config/logging.php`:

```php
'capell' => [
    'driver' => 'single',
    'path' => storage_path('logs/capell.log'),
    'level' => env('LOG_LEVEL', 'debug'),
],
```

For local development:

```env
QUEUE_CONNECTION=sync
DEBUG_SKIP_CACHE=true
CAPELL_HTML_CACHE=false
CAPELL_MINIFY_HTML=false
```

For production, use `database` or `redis` queues and run a worker.

### 7. Theme compilation

Add Capell sources to `resources/css/filament/admin/theme.css`:

```css
@source '../../../../vendor/capell-app/admin/resources/views/**/*.blade.php';
@source '../../../../storage/capell/tailwind-classes.txt';
@source '../../../../app/Filament/**/*';
@source '../../../../resources/views/filament/**/*';
```

If you install ContentSections, themes, or other approved packages with Filament views, add their `@source` lines too.

### 8. Run the CLI installer

You added `capell-app/installer` in step 3. Run the installer and choose the Capell packages. The admin package remains optional; when you select `capell-app/admin`, the installer checks Composer can resolve it, requires it during the install, scaffolds the Filament panel, applies the `User` model and Filament theme patches, and runs the admin setup steps:

```bash
php artisan capell:install --url=https://example.test
```

For a prompt-free admin install that also creates the first admin user, pass `--name`, `--email`, and `--password` together:

```bash
php artisan capell:install \
  --packages=capell-app/admin \
  --theme=none \
  --url=https://example.test \
  --name="Admin" --email=admin@example.test --password='change-me-now' \
  --clear-cache --remove-installer --no-interaction
```

On a fresh app there is no user yet, so create one with `--name/--email/--password`. Use `--user=<email-or-id>` instead only when the admin user already exists — it sets the default author for generated content rather than creating an account.

For a demo install:

```bash
php artisan capell:install --demo --url=http://localhost:8000
```

Common installer flags:

| Flag                               | Use it for                                                                        |
| ---------------------------------- | --------------------------------------------------------------------------------- |
| `--demo`                           | Seed sample sites, pages, and content                                             |
| `--plan`                           | Print the exact install plan and exit without changing anything                   |
| `--url=https://...`                | Set the site URL (skips the prompt)                                               |
| `--packages=core,admin`            | Comma-separated packages to install                                               |
| `--package-mode=core\|all\|custom` | Choose the package set without listing each package                               |
| `--all-packages`                   | Install every Composer-installed Capell package                                   |
| `--theme=corporate`                | Activate a starter theme (`--theme=none` installs without one)                    |
| `--name=` `--email=` `--password=` | Create the first admin user non-interactively (pass all three)                    |
| `--user=email-or-id`               | Use an existing user as the default author for generated content                  |
| `--clear-cache`                    | Clear Laravel and page caches after install                                       |
| `--generate-sitemap`               | Generate sitemaps (when a sitemap package is installed)                           |
| `--seed`                           | Run the application's database seeder after installing                            |
| `--install-welcome-route`          | Remove Laravel's default welcome route so Capell owns `/`                         |
| `--developer-tooling`              | Install Laravel Boost and Capell Agent Bridge developer tooling                   |
| `--remove-installer`               | Remove `capell-app/installer` after a successful install                          |
| `--fresh` / `--fresh=force`        | Run `migrate:fresh` first — deletes data (`=force` skips the confirmation)        |
| `--production`                     | Unattended, production-safe mode: forces `--no-interaction` and refuses `--fresh` |

Interactive installs ask which starter theme to install, including a no-theme option. Use `--theme=none` to install without a starter theme non-interactively, or pass a specific package-provided theme key. For example:

```bash
php artisan capell:install --packages=capell-app/theme-corporate --theme=corporate --url=https://example.test
```

If the installer package is present, interactive installs can remove `capell-app/installer` at the end. Use `--remove-installer` for prompt-free installs. Removal only runs after the install succeeds; failed package requirements, Filament scaffolding, admin integration, health checks, or install actions leave the installer installed so you can inspect the report and retry.

### 9. Verify the install

```bash
php artisan optimize:clear
php artisan list capell
```

If `capell-app/html-cache` is installed, you can also run `php artisan capell:static-site` to warm the public HTML cache. Open the app through Herd, Valet, Sail, or your normal local web server. Then visit `/admin`. You should see the dashboard:

![Capell admin dashboard](../images/admin-dashboard.png)

Then open **Pages** and confirm the page tree loads:

![Capell pages list](../images/admin-pages-list.png)

## Path B: existing Laravel app

Before installing, back up your database and review route ownership. Capell can own the frontend routes, but you may already have application routes that need to stay first.

1. Confirm the app runs on PHP 8.4+ and Laravel 12.41.1+ or 13.x.
2. Add Capell repositories if needed.
3. Install `capell-app/installer` (it pulls in core; the guided installer adds admin/frontend) or require the packages you want directly.
4. Update your existing `User` model instead of replacing it.
5. Merge the Capell panel configuration into your existing Filament panel.
6. Add the `page_cache` disk and `capell` log channel.
7. Run the installer, pointing `--user` at an email or ID that already exists: `php artisan capell:install --user=admin@example.com --url=https://your-site.test`. Admin access comes from the `User` model traits in step 4 and the roles the installer sets up; `--user` only sets the default author for generated content. To create a brand-new admin instead, pass `--name`, `--email`, and `--password` together.
8. Remove Laravel's default welcome route only if Capell should handle `/`.
9. If `capell-app/html-cache` is installed, run `php artisan capell:static-site` and preview a non-critical page first.

If you already have content, read [Multi-site & Multi-lingual](https://docs.capell.app/multi-site-multi-lingual/) before creating sites and languages.

## Optional add-ons

Install only the packages you need:

| Need                         | Package                       | Commands                                                                                      |
| ---------------------------- | ----------------------------- | --------------------------------------------------------------------------------------------- |
| Visual page builder          | `capell-app/content-sections` | `composer require capell-app/content-sections && php artisan capell:content-sections-install` |
| Blog/articles                | `capell-app/blog`             | `composer require capell-app/blog && php artisan capell:blog-install`                         |
| Address fields               | `capell-app/address`          | `composer require capell-app/address && php artisan capell:address-install`                   |
| SEO and AI-assisted metadata | `capell-app/seo-suite`        | `composer require capell-app/seo-suite && php artisan capell:seo-suite-install`               |
| Curator media backend        | `capell-app/media-library`    | `composer require capell-app/media-library`                                                   |

See [approved packages](../packages/catalog.md) for dependencies and tradeoffs.

## Web server setup

For production performance, configure Apache or Nginx to serve the installed cache package's generated HTML path before PHP. See [server configuration](https://docs.capell.app/packages/frontend/server-config/) for the frontend routing rules, then apply the cache package's documented output path.

## Next

- [Quickstart](quickstart.md) for a fast demo path.
- [Operations](../operations/index.md) for common first-run failures.
- [Frontend](../frontend/index.md) for caching, Tailwind, translations, and site resolution.
