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
| `CAPELL_CACHE_LOCK_SECONDS`                  | `30`                                      | Lifetime in seconds of the lock Capell holds while filling a cache entry, preventing stampedes      |
| `CAPELL_CACHE_LOCK_WAIT_SECONDS`             | `10`                                      | How long a request waits for another process to finish filling the same cache entry before failing  |
| `CAPELL_MULTI_NODE`                          | `false`                                   | Declare a multi-node deployment so Doctor requires a shared cache store                              |
| `CAPELL_ASSETS_DISK`                         | `local`                                   | Filesystem disk checked by Capell Doctor for writable asset storage                                 |
| `CAPELL_SITEMAP_MAX_URLS_PER_FILE`           | `50000`                                   | Maximum URLs per generated sitemap file                                                             |
| `CAPELL_SITEMAP_XML_PATH`                    | `/sitemap-xml`                            | Public path used for sitemap index entries                                                          |
| `CAPELL_STATIC_SITE_INTERNAL_REQUESTS`       | `false`                                   | Allows static-site generation to use internal request handling                                      |
| `CAPELL_BLAZE_ENABLED`                       | `BLAZE_ENABLED`, then `true`              | Enables Blaze support where installed                                                               |
| `BLAZE_DEBUG`                                | `false`                                   | Enables Blaze debug behaviour                                                                       |
| `CAPELL_BLAZE_THROW`                         | `false`                                   | Throws during Blaze candidate auditing                                                              |
| `CAPELL_DISABLE_CACHE`                       | `false`                                   | Disables Capell's general cache layer; set to `true` only for explicit uncached operation           |
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
| `CAPELL_FRONTEND_STATIC_ARTIFACTS_PATH`      | _(null)_      | Override directory for `capell:generate-html` output; defaults to storage |
| `CAPELL_FRONTEND_TAILWIND_OUTPUT_CSS`        | `resources/css/capell/frontend.css` | Path the generated frontend Tailwind stylesheet is written to |
| `CAPELL_FRONTEND_EXTERNAL_INTEGRITY_POLICY`  | `warn`        | Subresource-integrity policy for external assets: `off`, `warn`, or `require` |
| `CAPELL_CACHE_INVALIDATION_GRAPH_MAX_DEPTH`  | `20`          | Maximum traversal depth when resolving which cache entries a change invalidates |
| `CAPELL_CACHE_INVALIDATION_GRAPH_MAX_NODES`  | `5000`        | Safety bound on nodes visited during cache-invalidation traversal   |
| `CAPELL_CACHE_INVALIDATION_GRAPH_MAX_EDGES`  | `10000`       | Safety bound on edges walked during cache-invalidation traversal    |
| `CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED` | `true`   | Detects database queries executed from public Blade views           |
| `CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_MODE` | `exception` | Guard reaction: `exception` to fail loudly, or `log` to record and continue |
| `CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_DOCS_URL` | _(docs.capell.app symptom table)_ | Help URL embedded in the guard's error message |
| `CAPELL_FRONTEND_PUBLIC_RENDER_CONTRACT_RECORD_PASSED` | `false` | Records passing public render-contract checks as events           |
| `CAPELL_FRONTEND_PUBLIC_RENDER_CONTRACT_RECORD_FAILED` | `true`  | Records failing public render-contract checks as events            |

The public view query guard is a development safeguard: queries belong in the render
pipeline, not in Blade. Set `CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_MODE=log` in
production if you would rather record violations than return an error page. Any value
other than `log` is treated as `exception`.

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
| `CAPELL_MARKETPLACE_QUEUE_CONNECTION`                      | `database`                                                 | Queue connection Marketplace install jobs are sent to |
| `CAPELL_MARKETPLACE_QUEUE`                                 | `capell-marketplace`                                       | Named queue Marketplace install jobs are sent to     |

Marketplace installs do not use `QUEUE_CONNECTION`. They are pinned to
`CAPELL_MARKETPLACE_QUEUE_CONNECTION` (default `database`) and the named queue
`capell-marketplace`. A worker started as plain `php artisan queue:work` consumes the
`default` queue and will never pick these jobs up — the admin UI stays at "queued"
with no error. Run a worker that names the queue, and allow enough time for Composer:

```bash
php artisan queue:work database --queue=capell-marketplace --timeout=900
```

If you set this connection to `sync`, the Composer install runs inside the web request
and will be killed by `max_execution_time`. Keep it on a real queue connection.

Only override `CAPELL_MARKETPLACE_URL` for staging or self-hosted Marketplace APIs. If Marketplace reports that `api/registration-sessions` cannot be found, the app is probably using an old unversioned API URL.

## Security-Relevant Keys Without Env Vars

These change what anonymous visitors can reach, or what protections apply. None has an
env var, so they are only settable by publishing the config file — and easy to miss.

| Config key | Default | Effect |
| --- | --- | --- |
| `capell-admin.security_headers.headers` | `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, `X-Frame-Options: SAMEORIGIN` | Literal header map stamped on every admin response. Publishing replaces the whole map, so add a CSP or change the frame policy here. Only the `enabled` flag has an env var. |
| `capell-frontend.fallback_public_views.view_names` | `[]` | Allowlist of full Blade view names an unresolved public path may render |
| `capell-frontend.fallback_public_views.prefixes` | `['pages']` | Permitted leading view segments for fallback views. Widening this exposes more application templates to anonymous visitors. |
| `capell-frontend.route.reserved_domains` | `[]` | Hosts the frontend catch-all must never serve. The admin domain is reserved automatically. |
| `capell-frontend.route.reserved_prefixes` | `[]` | Path prefixes excluded from frontend catch-all routing |
| `capell-frontend.route.reserved_exact_paths` | `[]` | Exact paths excluded from frontend catch-all routing |
| `capell-frontend.route.url_regex` | negative-lookahead regex | Decides which URLs the frontend catch-all claims. Excludes admin, api, livewire, storage, and asset extensions; allows `.html`. |
| `capell-frontend.public_view_query_guard.ignored_connections` | `[]` | Connections exempt from the public-render query guard. Adding one silently disables leak detection for it. |
| `capell-frontend.public_html_authoring_markers` | `[]` | Extra substrings treated as unsafe authoring markers when inspecting public HTML |
| `capell-frontend.cache_skip_authenticated` | `true` | Bypasses the frontend HTML cache for signed-in requests. Leave enabled unless you are certain no per-user content is rendered. |
| `capell.runtime.auth_paths` | `login`, `register`, `forgot-password`, `reset-password/*`, `email/verify*`, `confirm-password`, `two-factor-challenge` | Path patterns the runtime resolver classifies as auth context |
| `capell-admin.user_resource.role_schema_types` | `['super_admin' => 'administrator']` | Maps a role to the user form schema it sees, controlling which fields that role can edit |
| `redirects.auto_redirects.status_code` | `301` | HTTP status for redirects created automatically when a page URL changes. Only `enabled` has an env var. |

## Other Keys Without Env Vars

Commonly adjusted, and not settable through the environment:

| Config key | Default | Effect |
| --- | --- | --- |
| `capell.default_pages` | `home`, `error_404`, `maintenance`, `welcome` | Pages created automatically for a new site |
| `capell.media.crop_presets` | `thumbnail` 320×320, `card` 800×600, `hero` 1600×900, `open_graph` 1200×630 | Named crop presets offered on media edit; changing these changes generated derivative sizes |
| `capell.media.model` | `Capell\Core\Models\Media` | Media model class override |
| `capell-frontend.default_layout` / `foundation_theme` | `default` / `default` | Fallbacks when a site or page specifies none |
| `capell-frontend.redirect_default_site` | `true` | Redirects unmatched hosts to the default site instead of returning 404 |
| `capell-frontend.pagination_limit` | `12` | Page-list page size when a caller passes no limit |
| `capell-frontend.meta_title_seperator` | `' \| '` | Separator between site and page name in `<title>`. Note the spelling of the key. |
| `capell-frontend.date_format` | `M jS, Y` | Publish-date format on asset cards and tiles |
| `capell-frontend.tailwind.sources` | `resources/views/**/*.blade.php` | Globs scanned for Tailwind class extraction |
| `capell-frontend.tailwind.validate_sources` | `false` | Validates those globs before generating assets |
| `capell-admin.navigation_badge_counts` | `false` | Global switch for admin navigation badge count queries |
| `capell-admin.shortcuts` | `g p`, `g s`, `g t`, `g ,`, `g w`, `/` | Admin keyboard shortcut map |
| `capell-admin.social_types` | 8 networks | Social link types offered in the site social-icons form |
| `capell-installer.database_table_cache.store` | `file` | Cache store for the installer's table-existence cache |

### Keys read by optional packages, not by this repository

Several keys live in a host config file but are consumed by an optional package. They do
nothing until that package is installed, and searching this repository for a consumer
finds none — which does not mean they are dead. Do not remove them.

| Config key | Read by |
| --- | --- |
| `capell.publishing-studio.release_windows.*`, `.notifications.*`, `.review_policy.*` | `capell-app/publishing-studio` (`ReleaseWindowGuard`) and `capell-app/automation-studio` |
| `capell.sitemap.xml_path`, `.disk`, `.directory` | `capell-app/site-discovery` |
| `capell-admin.layout_builder.allowed_editor_modes` | `capell-app/layout-builder` (`LayoutBuilderConfiguration`) |
| `capell-frontend.breakpoints.lg` | `capell-app/theme-foundation` views |

Release windows in particular are enforced by the Publishing Studio package. Without it
installed, setting those keys restricts nothing.

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
