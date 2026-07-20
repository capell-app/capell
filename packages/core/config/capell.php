<?php

declare(strict_types=1);

use Capell\Core\Models\Media;

return [
    'version' => env('CAPELL_VERSION'),

    'cache_path' => env('CAPELL_CACHE_PATH', base_path('bootstrap/cache/capell')),
    'cache_ttl' => (int) env('CAPELL_CACHE_TTL', 60),

    'assets' => [
        'disk' => env('CAPELL_ASSETS_DISK', 'local'),
    ],

    'default_colors' => [
        'base' => 'rgb(15, 23, 42)',          // main text
        'white' => 'rgb(255, 255, 255)',
        'black' => 'rgb(0, 0, 0)',
        'primary' => 'rgb(37, 99, 235)',       // blue-600
        'secondary' => 'rgb(71, 85, 105)',     // slate-600
        'success' => 'rgb(22, 163, 74)',       // green-600
        'warning' => 'rgb(217, 119, 6)',       // amber-600
        'danger' => 'rgb(220, 38, 38)',        // red-600
        'info' => 'rgb(2, 132, 199)',          // sky-600
        'muted' => 'rgb(100, 116, 139)',       // slate-500
        'border' => 'rgb(226, 232, 240)',      // slate-200
        'gray' => 'rgb(69, 69, 69)',        // cards/modals
        'light_gray' => 'rgb(241, 245, 249)',  // subtle panels
        'dark_gray' => 'rgb(231, 231, 231)',   // dark panels
    ],

    'default_pages' => [
        'home',
        'error_404',
        'maintenance',
        'welcome',
    ],

    'sitemap' => [
        'disk' => 'local',
        'directory' => 'sitemaps',
        // Maximum number of URLs written to a single sitemap file.
        // When exceeded the generator creates numbered chunk files and a
        // <sitemapindex> index file.  Google's limit is 50 000.
        'max_urls_per_file' => env('CAPELL_SITEMAP_MAX_URLS_PER_FILE', 50000),
        // Public path appended to the domain base URL when building chunk
        // <loc> entries inside a sitemap index (e.g. /sitemap-xml).
        'xml_path' => env('CAPELL_SITEMAP_XML_PATH', '/sitemap-xml'),
    ],

    'static_site' => [
        'internal_requests' => env('CAPELL_STATIC_SITE_INTERNAL_REQUESTS', false),
    ],

    'runtime' => [
        'auth_paths' => [
            'login',
            'register',
            'forgot-password',
            'reset-password/*',
            'email/verify*',
            'confirm-password',
            'two-factor-challenge',
        ],
    ],

    'blaze' => [
        'enabled' => env('CAPELL_BLAZE_ENABLED', env('BLAZE_ENABLED', true)),
        'debug' => env('BLAZE_DEBUG', false),
        'throw' => env('CAPELL_BLAZE_THROW', false),
    ],

    // Skip caching if in debug mode
    'disable_cache' => env('CAPELL_DISABLE_CACHE', true),

    'debug' => [
        'relationship_diagnostics' => env('CAPELL_RELATIONSHIP_DIAGNOSTICS', false),
    ],

    /**
     * Prevent saving to cache for specific keys.
     * Accepts:
     *   - Exact string matches: e.g. 'my-key'
     *   - Wildcards: e.g. 'page-*' matches all keys starting with 'page-'
     *   - Regex: e.g. '/^user-\\d+$/' matches 'user-123', 'user-456', etc.
     * Example:
     *   'disable_cache_save_keys' => ['page-*', '/^user-\\d+$/', 'my-key'],
     */
    'disable_cache_save_keys' => env('CAPELL_DISABLE_CACHE_SAVE_KEYS', []),

    'publishing-studio' => [
        // When true, Capell registers a nightly schedule that runs
        // `capell:publishing-studio:prune` at 03:15 server time to release
        // shadowed live rows and delete abandoned publishing-studio.
        'prune_schedule_enabled' => env('CAPELL_WORKSPACES_PRUNE_SCHEDULE', false),
        'prune_schedule_cron' => env('CAPELL_WORKSPACES_PRUNE_CRON', '15 3 * * *'),

        'preview' => [
            'home_route' => env('CAPELL_WORKSPACE_PREVIEW_HOME_ROUTE', 'capell-frontend.home'),
        ],

        // Notifications fired on editorial state transitions. Each transition
        // maps to one or more role names (matched against the Spatie role
        // table). `channels` is the list of notification channels a listener
        // sends via; `mail` is always available, additional channels (slack,
        // teams) require their notifiables to be configured separately.
        'notifications' => [
            'enabled' => env('CAPELL_WORKSPACE_NOTIFICATIONS', true),
            'channels' => ['mail'],
            'recipients' => [
                'submitted' => ['workspace_reviewer', 'workspace_release_manager'],
                'approved' => ['workspace_editor', 'workspace_release_manager'],
                'rejected' => ['workspace_editor'],
                'changes_requested' => ['workspace_editor'],
                'published' => ['workspace_editor', 'workspace_reviewer', 'workspace_release_manager'],
                'abandoned' => [],
            ],
        ],

        // Review policy — controls which roles must sign off before a workspace
        // can be approved, based on the content blueprints it contains.
        //
        // 'default' applies when no content-type-specific rule matches.
        //   'minimum' sets how many generic reviewers are required.
        //
        // 'content_types' maps a draftable model class to a list of Spatie role
        //   names that must have at least one member approve the workspace.
        //   Useful when certain page blueprints (e.g. legal pages) need a specialist
        //   to sign off in addition to the standard reviewer tier.
        //
        // Example:
        //   'review_policy' => [
        //       'default' => ['minimum' => 1],
        //       'content_types' => [
        //           \App\Models\Page::class => ['required_roles' => ['legal']],
        //       ],
        //   ],
        //
        // Note: this controls *who must review*. The number of approvals needed
        // to flip a workspace to 'approved' is set per-workspace via the
        // "Approval workflow" section in the workspace edit form.
        'review_policy' => [
            'default' => ['minimum' => 1],
            'content_types' => [],
        ],

        // Optional publish release windows. When enabled, Publisher refuses
        // to flip a workspace live outside the configured windows unless
        // the caller passes `bypassWindow: true` (gated in the admin by the
        // `publish_outside_release_window` permission).
        'release_windows' => [
            'enabled' => env('CAPELL_RELEASE_WINDOWS', false),
            'timezone' => env('CAPELL_RELEASE_WINDOWS_TZ', 'UTC'),
            'windows' => [
                // Example: Mon–Fri 09:00–17:00 local time.
                // ['days' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'start' => '09:00', 'end' => '17:00'],
            ],
            'bypass_permission' => 'publish_outside_release_window',
        ],
    ],

    // Plugin packages remote source URL
    'plugins_source_url' => env('CAPELL_PLUGINS_SOURCE_URL', 'https://plugin.capell.app/packages.json'),
    // Cache TTL in seconds for plugin packages
    'plugins_cache_ttl' => env('CAPELL_PLUGINS_CACHE_TTL', 3600),
    // Marketplace public web URL for package/theme image paths when marketplace config is not installed.
    'marketplace_web_url' => env('CAPELL_MARKETPLACE_WEB_URL', 'https://capell.app'),
    // Cached plugin packages list
    'plugins' => [],

    'media' => [
        'backend' => env('CAPELL_MEDIA_BACKEND', 'spatie'),
        'model' => Media::class,
        'crop_presets' => [
            'thumbnail' => [
                'label' => 'Thumbnail',
                'ratio' => '1:1',
                'width' => 320,
                'height' => 320,
            ],
            'card' => [
                'label' => 'Card',
                'ratio' => '4:3',
                'width' => 800,
                'height' => 600,
            ],
            'hero' => [
                'label' => 'Hero',
                'ratio' => '16:9',
                'width' => 1600,
                'height' => 900,
            ],
            'open_graph' => [
                'label' => 'Open Graph',
                'ratio' => '1200:630',
                'width' => 1200,
                'height' => 630,
            ],
        ],
    ],

    'roles' => [
        'super_admin' => env('CAPELL_SUPER_ADMIN_ROLE', 'super_admin'),
        'admin' => env('CAPELL_ADMIN_ROLE', 'admin'),
        'editor' => env('CAPELL_EDITOR_ROLE', 'editor'),
        'developer' => env('CAPELL_DEVELOPER_ROLE', 'developer'),
    ],

    'install' => [
        'debug' => (bool) env('CAPELL_INSTALL_DEBUG', env('CAPELL_INSTALL_DEBUG_PACKAGE_SELECTION', false)),
        'debug_package_selection' => (bool) env('CAPELL_INSTALL_DEBUG_PACKAGE_SELECTION', env('CAPELL_INSTALL_DEBUG', false)),
        'welcome_routes_web_path' => env('CAPELL_INSTALL_WELCOME_ROUTES_WEB_PATH', base_path('routes/web.php')),
        'welcome_env_path' => env('CAPELL_INSTALL_WELCOME_ENV_PATH', base_path('.env')),

        'admin_user' => [
            'name' => env('CAPELL_SETUP_ADMIN_NAME', ''),
            'email' => env('CAPELL_SETUP_ADMIN_EMAIL', ''),
            'password' => env('CAPELL_SETUP_ADMIN_PASSWORD', ''),
        ],
    ],

    'cloud' => [
        'install_mode' => env('CAPELL_INSTALL_MODE'),
        'registration_url' => env('CAPELL_CLOUD_REGISTRATION_URL'),
        'registration_token' => env('CAPELL_REGISTRATION_TOKEN'),
        'site_url' => env('CAPELL_SITE_URL'),
        'install_packages' => env('CAPELL_INSTALL_PACKAGES', ''),
        'site_build_compatibility_payload' => env('CAPELL_SITE_BUILD_COMPATIBILITY_PAYLOAD', ''),
        'site_build_compatibility_signature' => env('CAPELL_SITE_BUILD_COMPATIBILITY_SIGNATURE', ''),
        'install_theme' => env('CAPELL_INSTALL_THEME', 'default'),
        'admin_user' => [
            'name' => env('CAPELL_ADMIN_NAME', 'Admin'),
            'email' => env('CAPELL_ADMIN_EMAIL', ''),
        ],
    ],

    'lockdown' => [
        'file' => env('CAPELL_LOCKDOWN_FILE', storage_path('framework/capell-lockdown.json')),
        'break_glass_user_ids' => (static fn (string $value): array => array_values(array_filter(
            explode(',', $value),
            static fn (string $entry): bool => $entry !== '',
        )))(env('CAPELL_LOCKDOWN_USER_IDS', '')),
        'break_glass_emails' => (static fn (string $value): array => array_values(array_filter(
            explode(',', $value),
            static fn (string $entry): bool => $entry !== '',
        )))(env('CAPELL_LOCKDOWN_EMAILS', '')),
    ],

    'dashboard' => [
        'developer_page_enabled' => env('CAPELL_DEVELOPER_PAGE', true),
        'system_health_enabled' => env('CAPELL_SYSTEM_HEALTH_PAGE', true),
    ],

    'diagnostics' => [
        'php_writes' => env('CAPELL_DEVELOPER_TOOLS_PHP_WRITES', 'local_only'),
        'database_writes' => env('CAPELL_DEVELOPER_TOOLS_DATABASE_WRITES', 'local_only'),
        'readonly_preview' => env('CAPELL_DEVELOPER_TOOLS_READONLY_PREVIEW', true),
        'editor_url_template' => env('CAPELL_DEVELOPER_TOOLS_EDITOR_URL_TEMPLATE'),
        'allowed_roots' => [
            app_path('Actions'),
            app_path('Blueprints'),
            app_path('Data'),
            app_path('Extenders'),
            app_path('Filament/Widgets'),
            app_path('Filament/Schemas'),
            app_path('Livewire'),
            app_path('Schemas'),
            app_path('Types'),
            resource_path('views'),
        ],
    ],
];
