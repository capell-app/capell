<?php

declare(strict_types=1);

use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Filament\Support\Icons\Heroicon;

$dangerThreshold = (int) env('CAPELL_UPDATE_DANGER_THRESHOLD', 3);

$apiTimeoutSeconds = (int) env('CAPELL_UPDATE_API_TIMEOUT_SECONDS', 10);

$notificationEmailsValue = env('CAPELL_UPDATE_NOTIFICATION_EMAILS', '');

$notificationEmails = array_values(array_filter(
    array_map(
        trim(...),
        explode(',', (string) $notificationEmailsValue),
    ),
    static fn (string $email): bool => $email !== '',
));

return [
    'path' => env('CAPELL_ADMIN_PATH', 'admin'),

    'domain' => env('CAPELL_ADMIN_DOMAIN'),

    'auto_clear_cache' => env('CAPELL_AUTO_CLEAR_CACHE', true),

    'auto_refresh_cache' => env('CAPELL_AUTO_REFRESH_CACHE', false),

    'security_headers' => [
        'enabled' => env('CAPELL_ADMIN_SECURITY_HEADERS_ENABLED', true),
        'headers' => [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'X-Frame-Options' => 'SAMEORIGIN',
        ],
    ],

    'resources' => [
        'page_redirects' => [
            'icon' => 'heroicon-o-arrow-uturn-right',
            'navigation_badge' => true,
        ],
        'layout' => [
            'icon' => 'heroicon-o-squares-2x2',
            'active_icon' => 'heroicon-s-squares-2x2',
        ],
        'type' => [
            'icon' => 'heroicon-o-cog-6-tooth',
            'active_icon' => 'heroicon-s-cog-6-tooth',
        ],
        'blueprint' => [
            'icon' => Heroicon::OutlinedDocumentDuplicate,
            'active_icon' => Heroicon::DocumentDuplicate,
        ],
    ],

    'user_resource' => [
        'default_schema_type' => 'default',
        'role_schema_types' => [
            'super_admin' => 'administrator',
        ],
    ],

    'assets' => [
        'media' => [
            'icon' => 'heroicon-o-photo',
            'model' => Media::class,
            'color' => 'info',
        ],
        'page' => [
            'navigation_badge' => false,
            'icon' => 'heroicon-o-rectangle-stack',
            'model' => Page::class,
            'color' => 'secondary',
        ],
        'user' => [
            'navigation_badge' => false,
            'icon' => 'heroicon-o-user-circle',
        ],
    ],

    'social_types' => [
        'facebook' => [
            'name' => 'Facebook',
            'url' => 'https://facebook.com/',
            'icon' => 'fab-facebook-f',
            'title' => 'Like us on facebook',
        ],
        'instagram' => [
            'name' => 'Instagram',
            'url' => 'https://www.instagram.com/',
            'icon' => 'fab-instagram',
            'title' => 'Follow us on Instagram',
        ],
        'whatsapp' => [
            'name' => 'WhatsApp',
            'url' => 'https://wa.me/',
            'icon' => 'fab-whatsapp',
            'title' => 'Chat with us on WhatsApp',
        ],
        'tiktok' => [
            'name' => 'Tiktok',
            'url' => 'https://www.tiktok.com/',
            'icon' => 'fab-tiktok',
            'title' => 'Follow us on TikTok',
        ],
        'pinterest' => [
            'name' => 'Pintrest',
            'url' => 'https://www.pinterest.com/',
            'icon' => 'fab-pinterest',
            'title' => 'Follow us on Pinterest',
        ],
        'linkedin' => [
            'name' => 'LinkedIn',
            'url' => 'https://linkedin.com/',
            'icon' => 'fab-linkedin-in',
            'title' => 'Connect with us on LinkedIn',
        ],
        'twitter' => [
            'name' => 'X (Twitter)',
            'url' => 'https://twitter.com/',
            'icon' => 'fab-x-twitter',
            'title' => 'Follow us on X (Twitter)',
        ],
        'youtube' => [
            'name' => 'YouTube',
            'url' => 'https://youtube.com/',
            'icon' => 'fab-youtube',
            'title' => 'Subscribe to our YouTube channel',
        ],
    ],
    'icon' => [
        'admin' => 'heroicon-o-cog-6-tooth',
        'theme' => 'heroicon-o-paint-brush',
        'colors' => 'heroicon-o-swatch',
    ],
    'navigation_badge_counts' => false,
    'auto_translate_language_text' => env('CAPELL_AUTO_TRANSLATE_LANGUAGE_TEXT', true),
    'show_configurator_type_hint' => env('CAPELL_SHOW_CONFIGURATOR_TYPE_HINT', false),

    'layout_builder' => [
        'default_editor_mode' => env('CAPELL_LAYOUT_BUILDER_DEFAULT_EDITOR_MODE', 'content_first'),
        'allowed_editor_modes' => ['content_first', 'layout_first'],

        'preview' => [
            /*
             * The public foundation theme stacks partial layout containers below
             * the large breakpoint. Keep the admin breakpoint preview aligned
             * with that frontend behavior by default.
             */
            'match_frontend_container_layout' => env('CAPELL_LAYOUT_BUILDER_PREVIEW_MATCH_FRONTEND_CONTAINER_LAYOUT', true),
        ],
    ],

    'upgrades' => [
        'danger_threshold' => $dangerThreshold,
        'api_enabled' => env('CAPELL_UPDATE_API_ENABLED', true),
        'api_url' => env('CAPELL_UPDATE_API_URL', 'https://capell.app/api/updates/check'),
        'api_timeout_seconds' => $apiTimeoutSeconds,
        'enforce_https' => env('CAPELL_UPDATE_API_ENFORCE_HTTPS', true),
        'notifications' => [
            'enabled' => env('CAPELL_UPDATE_NOTIFICATIONS_ENABLED', true),
            'frequency' => env('CAPELL_UPDATE_NOTIFICATION_FREQUENCY', 'weekly'),
            'emails' => $notificationEmails,
        ],
    ],

    'extensions' => [
        'composer_drift' => [
            'auto_fix' => (bool) env('CAPELL_EXTENSIONS_COMPOSER_DRIFT_AUTO_FIX', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Keyboard shortcuts
    |--------------------------------------------------------------------------
    |
    | Global keyboard shortcuts for the admin panel. Sequences (like "g p") are
    | detected via an Alpine.js key listener registered in the admin layout.
    |
    | Keys:
    |   - "sequence" — space-separated key sequence (e.g. "g p")
    |   - "key"      — single key press (e.g. "/")
    |   - "action"   — "navigate" (go to URL) or "focus" (focus selector)
    |   - "target"   — URL (for navigate) or CSS selector (for focus)
    |
    */
    'shortcuts' => [
        [
            'sequence' => 'g p',
            'action' => 'navigate',
            'target' => 'pages',
            'label' => 'Go to Pages',
        ],
        [
            'sequence' => 'g s',
            'action' => 'navigate',
            'target' => 'sites',
            'label' => 'Go to Sites',
        ],
        [
            'sequence' => 'g t',
            'action' => 'navigate',
            'target' => 'themes',
            'label' => 'Go to Themes',
        ],
        [
            'sequence' => 'g ,',
            'action' => 'navigate',
            'target' => 'settings',
            'label' => 'Go to Settings',
        ],
        [
            'sequence' => 'g w',
            'action' => 'navigate',
            'target' => 'publishing-studio',
            'label' => 'Go to PublishingStudio',
        ],
        [
            'key' => '/',
            'action' => 'focus',
            'target' => '[data-filament-global-search-field]',
            'label' => 'Focus global search',
        ],
    ],
];
