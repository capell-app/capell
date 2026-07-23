<?php

declare(strict_types=1);

use Capell\Frontend\Livewire\Page\Page;

return [
    // UI & Layout
    'breakpoints' => [
        'lg' => 1024,
    ],
    // Optional: override default Blade layout file
    'layout_file' => env('CAPELL_FRONTEND_LAYOUT_FILE', 'capell::app'),
    'date_format' => 'M jS, Y',
    'pagination_limit' => 12,
    'container_width_default' => env('CAPELL_FRONTEND_CONTAINER_WIDTH_DEFAULT'),

    'external_resources' => [
        'integrity_policy' => env('CAPELL_FRONTEND_EXTERNAL_INTEGRITY_POLICY', 'warn'),
    ],
    'public_aggressive_prefetch' => env('CAPELL_FRONTEND_PUBLIC_AGGRESSIVE_PREFETCH', false),
    'cache_invalidation' => [
        'graph_max_depth' => (int) env('CAPELL_CACHE_INVALIDATION_GRAPH_MAX_DEPTH', 20),
        'graph_max_nodes' => (int) env('CAPELL_CACHE_INVALIDATION_GRAPH_MAX_NODES', 5000),
        'graph_max_edges' => (int) env('CAPELL_CACHE_INVALIDATION_GRAPH_MAX_EDGES', 10000),
    ],

    // Caching & Performance
    'html_cache' => env('CAPELL_HTML_CACHE', true),
    'write_html_cache' => env('CAPELL_WRITE_HTML_CACHE', true),
    'public_render_data_cache' => env('CAPELL_PUBLIC_RENDER_DATA_CACHE', true),
    'minify_html' => env('CAPELL_MINIFY_HTML', true),
    'cache_skip_authenticated' => true,
    'static_artifacts_path' => env('CAPELL_FRONTEND_STATIC_ARTIFACTS_PATH'),
    'public_html_authoring_markers' => [],
    /*
     * Allowlist for the public path -> Blade view fallback (RenderFallbackPublicViewAction).
     *
     * The fallback maps an unresolved request path to a Blade view name. Without an
     * allowlist an anonymous visitor can coerce arbitrary nested app views (e.g.
     * /admin/users -> view "admin.users") into rendering, disclosing non-public
     * templates. To stay safe:
     *
     *  - 'view_names' is an explicit list of fully permitted fallback view names.
     *  - 'prefixes'   is a list of permitted leading segments. A multi-segment view
     *                 name is only allowed when its first segment appears here
     *                 (e.g. prefix "pages" permits "pages.about" from /pages/about).
     *
     * Single-segment, author-authored top-level views (e.g. /about -> "about") remain
     * allowed by default. Namespaced names ("::"), traversal ("..") and control
     * characters are always rejected.
     */
    'fallback_public_views' => [
        'view_names' => [],
        'prefixes' => ['pages'],
    ],
    'public_view_query_guard' => [
        'enabled' => env('CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED', true),
        'mode' => env('CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_MODE', 'exception'),
        'ignored_connections' => [],
        'docs_url' => env('CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_DOCS_URL', 'https://docs.capell.app/core/frontend/debugging-public-output/#symptom-table'),
    ],
    'public_render_contract_events' => [
        'record_passed' => env('CAPELL_FRONTEND_PUBLIC_RENDER_CONTRACT_RECORD_PASSED', false),
        'record_failed' => env('CAPELL_FRONTEND_PUBLIC_RENDER_CONTRACT_RECORD_FAILED', true),
    ],
    /*
     * Compatibility escape hatch for trusted, developer-authored content only.
     * Keep false for editor-controlled CMS content in production because Blade
     * directives execute server-side.
     */
    'html_content_allowed_attributes' => ['class'],

    // Model Event Registration Mode
    // Controls how Capell tracks model events for cache updates.
    // Options:
    //   'sync': update cache immediately (reliable for most users)
    //   'deferred' (default): update cache after the response without a queue worker
    //   'async' (advanced): queue a background job (requires queue worker)
    // Set via env CAPELL_MODEL_EVENT_REGISTRATION_MODE=sync|deferred|async
    'model_event_registration_mode' => env('CAPELL_MODEL_EVENT_REGISTRATION_MODE', 'deferred'),

    // Domain & Routing
    'redirect_default_site' => true,
    'register_home_route' => env('CAPELL_FRONTEND_REGISTER_HOME_ROUTE', false),
    'use_site_domain_for_urls' => env('CAPELL_FRONTEND_USE_SITE_DOMAIN_FOR_URLS', false),
    // When true, a missing-sites configuration throws an exception instead of returning a 404.
    'throw_on_no_sites' => env('CAPELL_THROW_ON_NO_SITES', false),
    'system_pages' => [
        'auto_create_missing' => env('CAPELL_AUTO_CREATE_SYSTEM_PAGES', true),
    ],
    // Exclude admin, Livewire/internal/debug/storage routes, and static asset file extensions from frontend matching.
    // HTML URLs remain routable so cached/static page URLs like /about.html can resolve through Capell.
    'route' => [
        'url_regex' => '^(?!(admin|api|install(?:/.*)?|livewire(?:-[a-zA-Z0-9]+)?(?:/update)?|storage|_clockwork|_debugbar)(/|$))(?!.*\.(?![Hh][Tt][Mm][Ll]$)[a-zA-Z0-9]{2,5}$).*$',
        'reserved_prefixes' => [],
        'reserved_exact_paths' => [],
        // Hosts the frontend catch-all must never handle. The Filament admin
        // domain is reserved automatically when capell-admin.domain is set;
        // add other internal/service domains here. Matching is exact host only
        // (case-insensitive, port-stripped) — wildcard/route-pattern hosts such
        // as "{tenant}.example.com" are not expanded, so reserve concrete hosts.
        'reserved_domains' => [],
    ],
    // Set to "https" when generated frontend URLs must always use HTTPS.
    'default_scheme' => env('CAPELL_FRONTEND_DEFAULT_SCHEME'),
    'site_base_url' => env('CAPELL_SITE_BASE_URL'),

    // Livewire & Component Registration
    'livewire_components' => [
        'capell::page.page' => Page::class,
    ],
    'blade_components' => [
        // Add blade component aliases => class here if needed
    ],

    // Scheduling & Automation
    'schedule_page_cleaner' => env('CAPELL_SCHEDULE_PAGE_CLEANER', 'daily'),
    'purge_queue' => env('CAPELL_FRONTEND_PURGE_QUEUE', 'default'),

    // CDN surrogate-key purge integration.
    'cdn_provider' => env('CAPELL_FRONTEND_CDN_PROVIDER'),
    'cloudflare_purge_token' => env('CAPELL_FRONTEND_CLOUDFLARE_PURGE_TOKEN'),
    'cloudflare_zone_id' => env('CAPELL_FRONTEND_CLOUDFLARE_ZONE_ID'),
    'fastly_api_key' => env('CAPELL_FRONTEND_FASTLY_API_KEY'),
    'fastly_service_id' => env('CAPELL_FRONTEND_FASTLY_SERVICE_ID'),
    'varnish_url' => env('CAPELL_FRONTEND_VARNISH_URL'),

    // Debug & Diagnostics
    'debug_log' => env('CAPELL_DEBUG_LOG', false),

    // Separator for meta title construction (site | page)
    'meta_title_seperator' => ' | ',

    // Default frontend layout and theme (used if not overridden per site/page)
    'default_layout' => 'default',
    'foundation_theme' => 'default',

    'tailwind' => [
        'imports' => [],
        'plugins' => [],
        'sources' => [
            'resources/views/**/*.blade.php',
        ],
        'validate_sources' => false,
        'output_css' => env('CAPELL_FRONTEND_TAILWIND_OUTPUT_CSS', 'resources/css/capell/frontend.css'),
    ],
];
