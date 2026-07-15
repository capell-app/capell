# Configuration Reference


This page covers the host package configuration that a Laravel app may need to set directly. Install-facing setup lives in the [install guide](../getting-started/install.md); this page is for maintainers checking env vars, published config, disks, logs, and panel wiring.

## Publishable Config

Capell host packages publish config by package tag:

```bash
php artisan vendor:publish --tag=capell-core-config
php artisan vendor:publish --tag=capell-admin-config
php artisan vendor:publish --tag=capell-frontend-config
```

Installer and Marketplace config are loaded from their packages. Publish or override them only when the app has a real deployment reason to do so.

## Application Baseline

| Variable           | Default                       | Used for                                                                                             |
| ------------------ | ----------------------------- | ---------------------------------------------------------------------------------------------------- |
| `APP_URL`          | `http://localhost` in Laravel | Default site URL, install prompts, generated callbacks, Marketplace webhook fallback                 |
| `APP_KEY`          | _(empty until generated)_     | Laravel encryption, signed URLs, cookies, and sessions                                               |
| `APP_ENV`          | Laravel app default           | Runtime environment; live public domains should normally use `production`                            |
| `APP_DEBUG`        | Laravel app default           | Exception rendering; must be `false` before public traffic                                           |
| `CACHE_STORE`      | Laravel app default           | Cache backend for runtime state, package flows, locks, and database-backed diagnostics               |
| `QUEUE_CONNECTION` | `sync` in a fresh Laravel app | Background jobs and package workflows; production installs should normally use `database` or `redis` |
| `SESSION_DRIVER`   | Laravel app default           | Session persistence for admins and authenticated users                                               |
| `LOG_LEVEL`        | Laravel default               | Capell log channel level when the app adds the recommended `capell` channel                          |

## Core Config

Source: `packages/core/config/capell.php`

| Variable                                     | Default                                   | Used for                                                                                            |
| -------------------------------------------- | ----------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `CAPELL_VERSION`                             | _(null)_                                  | Optional Capell version override for Marketplace health reports                                     |
| `CAPELL_CACHE_PATH`                          | `bootstrap/cache/capell`                  | Directory used for Capell component cache files                                                     |
| `CAPELL_CACHE_TTL`                           | `60`                                      | Default TTL in seconds for Capell's general cache helpers                                           |
| `CAPELL_ASSETS_DISK`                         | `local`                                   | Filesystem disk checked by Capell Doctor for writable asset storage                                 |
| `CAPELL_SITEMAP_MAX_URLS_PER_FILE`           | `50000`                                   | Maximum URLs per generated sitemap file                                                             |
| `CAPELL_SITEMAP_XML_PATH`                    | `/sitemap-xml`                            | Public path used for sitemap index entries                                                          |
| `CAPELL_STATIC_SITE_INTERNAL_REQUESTS`       | `false`                                   | Allows static-site generation to use internal request handling                                      |
| `CAPELL_BLAZE_ENABLED`                       | `BLAZE_ENABLED`, then `true`              | Enables Blaze support where installed                                                               |
| `BLAZE_DEBUG`                                | `false`                                   | Enables Blaze debug behaviour                                                                       |
| `CAPELL_BLAZE_THROW`                         | `false`                                   | Throws during Blaze candidate auditing                                                              |
| `CAPELL_DISABLE_CACHE`                       | `true`                                    | Disables Capell's general cache layer                                                               |
| `CAPELL_DISABLE_CACHE_SAVE_KEYS`             | `[]`                                      | Cache keys or patterns that should not be saved                                                     |
| `CAPELL_RELATIONSHIP_DIAGNOSTICS`            | `false`                                   | Enables extra relationship diagnostics for page URL/site-domain checks                              |
| `CAPELL_WORKSPACES_PRUNE_SCHEDULE`           | `false`                                   | Enables the publishing workspace prune schedule                                                     |
| `CAPELL_WORKSPACES_PRUNE_CRON`               | `15 3 * * *`                              | Cron expression for workspace pruning                                                               |
| `CAPELL_WORKSPACE_PREVIEW_HOME_ROUTE`        | `capell-frontend.home`                    | Route used for workspace home previews                                                              |
| `CAPELL_WORKSPACE_NOTIFICATIONS`             | `true`                                    | Enables workspace state-change notifications                                                        |
| `CAPELL_RELEASE_WINDOWS`                     | `false`                                   | Enforces configured release windows during publishing                                               |
| `CAPELL_RELEASE_WINDOWS_TZ`                  | `UTC`                                     | Timezone used for release windows                                                                   |
| `CAPELL_PLUGINS_SOURCE_URL`                  | `https://plugin.capell.app/packages.json` | Legacy plugin source URL                                                                            |
| `CAPELL_PLUGINS_CACHE_TTL`                   | `3600`                                    | Legacy plugin cache TTL in seconds                                                                  |
| `CAPELL_MEDIA_BACKEND`                       | `spatie`                                  | Media backend resolver key, such as `spatie` or `curator`                                           |
| `CAPELL_SUPER_ADMIN_ROLE`                    | `super_admin`                             | Role name used for the highest admin role                                                           |
| `CAPELL_ADMIN_ROLE`                          | `admin`                                   | Role name used for admin users                                                                      |
| `CAPELL_EDITOR_ROLE`                         | `editor`                                  | Role name used for editor users                                                                     |
| `CAPELL_DEVELOPER_ROLE`                      | `developer`                               | Role name used by developer-focused admin widgets                                                   |
| `CAPELL_INSTALL_DEBUG`                       | `false`                                   | Logs installer events for support debugging                                                         |
| `CAPELL_INSTALL_DEBUG_PACKAGE_SELECTION`     | `false`                                   | Logs installer package-selection mode, prompt defaults, and selected packages for support debugging |
| `CAPELL_INSTALL_WELCOME_ROUTES_WEB_PATH`     | `routes/web.php`                          | Routes file patched when the installer toggles the welcome route                                    |
| `CAPELL_INSTALL_WELCOME_ENV_PATH`            | `.env`                                    | Env file patched when the installer toggles the welcome route                                       |
| `CAPELL_INSTALL_MODE`                        | _(null)_                                  | Enables `capell:cloud-bootstrap` when set to `cloud`                                                |
| `CAPELL_CLOUD_REGISTRATION_URL`              | _(null)_                                  | Capell Cloud registration endpoint used by `capell:cloud-bootstrap`                                 |
| `CAPELL_REGISTRATION_TOKEN`                  | _(null)_                                  | Secret token for Capell Cloud registration                                                          |
| `CAPELL_SITE_URL`                            | _(null)_                                  | Public site URL assigned by the cloud host; never falls back to `APP_URL`                           |
| `CAPELL_INSTALL_PACKAGES`                    | _(empty)_                                 | Comma-separated package list for cloud bootstrap installs                                           |
| `CAPELL_INSTALL_THEME`                       | `default`                                 | Theme key used by cloud bootstrap installs                                                          |
| `CAPELL_ADMIN_NAME`                          | `Admin`                                   | Bootstrap admin name fallback when cloud credentials omit a name                                    |
| `CAPELL_ADMIN_EMAIL`                         | _(empty)_                                 | Bootstrap admin email fallback when cloud credentials omit an email                                 |
| `CAPELL_LOCKDOWN_FILE`                       | `storage/framework/capell-lockdown.json`  | Lockdown state file path                                                                            |
| `CAPELL_LOCKDOWN_USER_IDS`                   | _(empty)_                                 | Comma-separated user IDs allowed to bypass Lockdown                                                 |
| `CAPELL_LOCKDOWN_EMAILS`                     | _(empty)_                                 | Comma-separated user emails allowed to bypass Lockdown                                              |
| `CAPELL_DEVELOPER_PAGE`                      | `true`                                    | Shows the developer dashboard page                                                                  |
| `CAPELL_SYSTEM_HEALTH_PAGE`                  | `true`                                    | Shows the system health dashboard page                                                              |
| `CAPELL_DEVELOPER_TOOLS_PHP_WRITES`          | `local_only`                              | Controls developer tooling PHP file writes                                                          |
| `CAPELL_DEVELOPER_TOOLS_DATABASE_WRITES`     | `local_only`                              | Controls developer tooling database writes                                                          |
| `CAPELL_DEVELOPER_TOOLS_READONLY_PREVIEW`    | `true`                                    | Keeps developer tooling previews read-only                                                          |
| `CAPELL_DEVELOPER_TOOLS_EDITOR_URL_TEMPLATE` | _(null)_                                  | Editor URL template for opening local files                                                         |

`CAPELL_DISABLE_CACHE_SAVE_KEYS` accepts exact keys, wildcard patterns, or regex patterns:

```env
CAPELL_DISABLE_CACHE_SAVE_KEYS=page-*,/^user-\d+$/,my-key
```

Source: `packages/core/config/redirects.php`

| Variable                        | Default | Used for                             |
| ------------------------------- | ------- | ------------------------------------ |
| `CAPELL_REDIRECTS_AUTO_ENABLED` | `true`  | Enables automatic redirect behaviour |

Source: `packages/core/config/audit.php`

| Variable           | Default | Used for                                                   |
| ------------------ | ------- | ---------------------------------------------------------- |
| `AUDITING_ENABLED` | `true`  | Enables auditing support where the audit package is active |

## Admin Config

Source: `packages/admin/config/capell-admin.php`

| Variable                                                        | Default                                | Used for                                                                                                                             |
| --------------------------------------------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| `CAPELL_ADMIN_PATH`                                             | `admin`                                | Filament panel path and admin-owned route prefix                                                                                     |
| `CAPELL_ADMIN_DOMAIN`                                           | _(null)_                               | Optional host restriction for admin panel and admin-owned routes                                                                     |
| `CAPELL_AUTO_CLEAR_CACHE`                                       | `true`                                 | Clears relevant caches after admin writes                                                                                            |
| `CAPELL_AUTO_REFRESH_CACHE`                                     | `false`                                | Refreshes page cache after admin writes where supported                                                                              |
| `CAPELL_ADMIN_SECURITY_HEADERS_ENABLED`                         | `true`                                 | Adds admin response security headers                                                                                                 |
| `CAPELL_AUTO_TRANSLATE_LANGUAGE_TEXT`                           | `true`                                 | Shows the auto-translate action on language repeater tabs                                                                            |
| `CAPELL_SHOW_CONFIGURATOR_TYPE_HINT`                            | `false`                                | Allows developer-only configurator class path hints in blueprint selectors when the admin setting also enables them                  |
| `CAPELL_LAYOUT_BUILDER_DEFAULT_EDITOR_MODE`                     | `content_first`                        | Default Layout Builder editor mode                                                                                                   |
| `CAPELL_LAYOUT_BUILDER_PREVIEW_MATCH_FRONTEND_CONTAINER_LAYOUT` | `true`                                 | Keeps admin layout previews aligned with frontend container behaviour                                                                |
| `CAPELL_UPDATE_DANGER_THRESHOLD`                                | `3`                                    | Version-behind count treated as dangerous in upgrade notices                                                                         |
| `CAPELL_UPDATE_API_ENABLED`                                     | `true`                                 | Enables update checks against the Capell update API                                                                                  |
| `CAPELL_UPDATE_API_URL`                                         | `https://capell.app/api/updates/check` | Update API endpoint                                                                                                                  |
| `CAPELL_UPDATE_API_TIMEOUT_SECONDS`                             | `10`                                   | Update API timeout                                                                                                                   |
| `CAPELL_UPDATE_API_ENFORCE_HTTPS`                               | `true`                                 | Requires HTTPS for update API calls                                                                                                  |
| `CAPELL_UPDATE_NOTIFICATIONS_ENABLED`                           | `true`                                 | Enables scheduled upgrade summary notifications                                                                                      |
| `CAPELL_UPDATE_NOTIFICATION_FREQUENCY`                          | `weekly`                               | Schedule frequency for upgrade summary notifications                                                                                 |
| `CAPELL_UPDATE_NOTIFICATION_EMAILS`                             | _(empty)_                              | Comma-separated email recipients for upgrade summaries                                                                               |
| `CAPELL_EXTENSIONS_COMPOSER_DRIFT_AUTO_FIX`                     | `false`                                | Allows all-package Composer drift repair through `capell:extensions:repair-composer-drift --all`; dashboard reads never run Composer |

Use the shared admin entrypoint helper when configuring Filament:

```php
use Capell\Admin\Support\AdminPanelEntrypoint;

return $panel
    ->domain(AdminPanelEntrypoint::domain())
    ->path(AdminPanelEntrypoint::path());
```

See [Admin domain and path](../admin/admin-domain.md) for path-only and subdomain examples.

## Frontend Config

Source: `packages/frontend/config/capell-frontend.php`

| Variable                                     | Default       | Used for                                                            |
| -------------------------------------------- | ------------- | ------------------------------------------------------------------- |
| `CAPELL_FRONTEND_LAYOUT_FILE`                | `capell::app` | Default Blade layout for frontend page rendering                    |
| `CAPELL_FRONTEND_CONTAINER_WIDTH_DEFAULT`    | _(null)_      | Default layout container width key when the layout has no override  |
| `CAPELL_FRONTEND_ASSET_BUILD_TOOL`           | `vite`        | Frontend build integration: `vite`, `mix`, or `static`              |
| `CAPELL_FRONTEND_PUBLIC_AGGRESSIVE_PREFETCH` | `false`       | Enables Laravel Vite aggressive prefetching for public assets       |
| `CAPELL_HTML_CACHE`                          | `true`        | Enables static HTML cache reads                                     |
| `CAPELL_WRITE_HTML_CACHE`                    | `true`        | Allows static HTML cache writes                                     |
| `CAPELL_PUBLIC_RENDER_DATA_CACHE`            | `true`        | Caches hydrated public render payloads                              |
| `CAPELL_MINIFY_HTML`                         | `true`        | Minifies rendered HTML before returning or caching                  |
| `CAPELL_MODEL_EVENT_REGISTRATION_MODE`       | `deferred`    | Cache event tracking mode: `sync`, `deferred`, or `async`           |
| `CAPELL_FRONTEND_REGISTER_HOME_ROUTE`        | `false`       | Registers `/` to the Capell frontend controller                     |
| `CAPELL_FRONTEND_USE_SITE_DOMAIN_FOR_URLS`   | `false`       | Rewrites generated frontend URLs to the resolved site domain        |
| `CAPELL_THROW_ON_NO_SITES`                   | `false`       | Throws instead of returning 404 when no sites exist                 |
| `CAPELL_AUTO_CREATE_SYSTEM_PAGES`            | `true`        | Auto-creates missing system pages when resolving fallback pages     |
| `CAPELL_FRONTEND_DEFAULT_SCHEME`             | _(null)_      | Optional forced scheme for generated frontend URLs, such as `https` |
| `CAPELL_SITE_BASE_URL`                       | _(null)_      | Base URL override for generated site URLs                           |
| `CAPELL_SCHEDULE_PAGE_CLEANER`               | `daily`       | Schedule frequency for page cleanup                                 |
| `CAPELL_FRONTEND_PURGE_QUEUE`                | `default`     | Queue used for CDN purge jobs                                       |
| `CAPELL_FRONTEND_CDN_PROVIDER`               | _(null)_      | CDN purge provider: `cloudflare`, `fastly`, or `varnish`            |
| `CAPELL_FRONTEND_CLOUDFLARE_PURGE_TOKEN`     | _(null)_      | Cloudflare API token for tag purges                                 |
| `CAPELL_FRONTEND_CLOUDFLARE_ZONE_ID`         | _(null)_      | Cloudflare zone ID for tag purges                                   |
| `CAPELL_FRONTEND_FASTLY_API_KEY`             | _(null)_      | Fastly API key for surrogate-key purges                             |
| `CAPELL_FRONTEND_VARNISH_URL`                | _(null)_      | Varnish endpoint used for BAN requests                              |
| `CAPELL_DEBUG_LOG`                           | `false`       | Adds frontend resolution debug logging                              |

HTML content rendering is sanitized by `RenderHtmlContentAction`; Blade directives are not evaluated. Allow only attributes that are safe for public CMS output through `capell-frontend.html_content_allowed_attributes`. See `packages/frontend/docs/security.md`.

## Installer Config

Source: `packages/installer/config/capell-installer.php`

| Variable                        | Default                   | Used for                                                |
| ------------------------------- | ------------------------- | ------------------------------------------------------- |
| `CAPELL_SETUP_ALLOW_REINSTALL`  | `APP_DEBUG`, then `false` | Allows the browser installer to run again after install |
| `CAPELL_SETUP_COMPOSER_BINARY`  | `composer`                | Composer binary used by the browser installer           |
| `CAPELL_SETUP_PHP_BINARY`       | `php`                     | CLI PHP binary used for fresh Artisan processes         |
| `CAPELL_SETUP_DEFAULT_PACKAGES` | `capell-app/filamentors`  | Optional packages preselected in the browser installer  |
| `CAPELL_SETUP_ADMIN_NAME`       | _(empty)_                 | First admin name prefilled in the browser installer     |
| `CAPELL_SETUP_ADMIN_EMAIL`      | _(empty)_                 | First admin email prefilled in the browser installer    |
| `CAPELL_SETUP_ADMIN_PASSWORD`   | _(empty)_                 | First admin plaintext password input; hashed on create  |

Set `CAPELL_SETUP_PHP_BINARY` when the web SAPI sees `PHP_BINARY` as `php-fpm` or another non-CLI executable.

For a local demo install, set the admin defaults as plaintext form values:

```dotenv
CAPELL_SETUP_ADMIN_NAME="Demo Admin"
CAPELL_SETUP_ADMIN_EMAIL=admin@example.test
CAPELL_SETUP_ADMIN_PASSWORD=password123
```

`CAPELL_SETUP_ADMIN_PASSWORD` is not encrypted in `.env` and should not be pre-hashed. Capell passes it through the setup flow as the login password, then Laravel hashes it before saving the admin user.

## Marketplace Config

Source: `packages/marketplace/config/capell-marketplace.php`

| Variable                                                   | Default                                                    | Used for                                            |
| ---------------------------------------------------------- | ---------------------------------------------------------- | --------------------------------------------------- |
| `CAPELL_MARKETPLACE_ENABLED`                               | `true`                                                     | Enables Marketplace integration                     |
| `CAPELL_INSTANCE_ID`                                       | _(null)_                                                   | Existing Marketplace instance ID                    |
| `CAPELL_MARKETPLACE_URL`                                   | `https://capell.app/api/v1`                                | Marketplace API base URL                            |
| `CAPELL_MARKETPLACE_WEB_URL`                               | `https://capell.app`                                       | Public Marketplace web URL                          |
| `capell-marketplace.marketplace.timeout_seconds`           | `10`                                                       | Marketplace API request timeout                     |
| `capell-marketplace.marketplace.telemetry_timeout_seconds` | `3`                                                        | Marketplace telemetry request timeout               |
| `capell-marketplace.marketplace.cache_ttl_seconds`         | `300`                                                      | Fresh Marketplace response cache TTL                |
| `capell-marketplace.marketplace.stale_cache_ttl_seconds`   | `3600`                                                     | Stale Marketplace response cache TTL                |
| `capell-marketplace.marketplace.warm_throttle_seconds`     | `60`                                                       | Minimum seconds between catalogue cache warm runs   |
| `CAPELL_MARKETPLACE_CATALOGUE_PAGE_LIMIT`                  | `3`                                                        | Page limit for catalogue fetches                    |
| `CAPELL_MARKETPLACE_WEBHOOK_URL`                           | _(null)_                                                   | Explicit public callback URL for Marketplace events |
| `CAPELL_MARKETPLACE_WEBHOOK_SECRET`                        | _(null)_                                                   | Shared secret for Marketplace webhook verification  |
| `CAPELL_MARKETPLACE_TROUBLESHOOTING_URL`                   | `https://docs.capell.app/extensions/marketplace-heartbeat` | Help URL shown in Marketplace diagnostics           |

Only override `CAPELL_MARKETPLACE_URL` for staging or self-hosted Marketplace APIs. If Marketplace reports that `api/registration-sessions` cannot be found, the app is probably using an old unversioned API URL.

## Optional Static HTML Cache Disk

Static HTML cache output is owned by the installed cache/static package, not by `capell-app/frontend` alone. Only add a dedicated disk when that package's docs or installer patch expects it. Older installs commonly use:

```php
'page_cache' => [
    'driver' => 'local',
    'root' => public_path('page-cache'),
    'throw' => false,
],
```

The web server user must be able to write to this path when the installed cache package has `CAPELL_WRITE_HTML_CACHE=true`. Do not configure Nginx or Apache to serve `public/page-cache` unless an installed cache package owns that directory and its public-output safety tests pass.

## Logging Channel

Add a dedicated Capell channel to `config/logging.php` if the installer has not patched it already:

```php
'capell' => [
    'driver' => 'single',
    'path' => storage_path('logs/capell.log'),
    'level' => 'debug',
],
```

Frontend logging uses this channel when it exists and falls back to Laravel logging when it does not.

## Morph Map

Register a morph map in the consuming app if polymorphic records may outlive class renames:

```php
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Blueprint;
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::morphMap([
    'language' => Language::class,
    'page' => Page::class,
    'site' => Site::class,
    'blueprint' => Blueprint::class,
]);
```

## Further Reading

- [Install guide](../getting-started/install.md)
- [Artisan commands](artisan-commands.md)
- [Admin setup](../admin/setup.md)
- [Frontend guide](../frontend/guide.md)
- [Operations troubleshooting](../operations/troubleshooting.md)
