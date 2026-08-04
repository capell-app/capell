<?php

declare(strict_types=1);

return [
    'cache_enabled' => 'Enable Page Cache',
    'cache_ttl' => 'Cache Time-to-Live',
    'cache_ttl_helper' => 'How long (in seconds) to keep pages cached before regenerating. Default is 3600 seconds (1 hour).',
    'custom_error_page_enabled' => 'Use custom error page',
    'custom_error_page_enabled_helper' => "Render Capell-managed 404 pages instead of Laravel's default 404 page.",
    'custom_maintenance_page_enabled' => 'Use custom maintenance page',
    'custom_maintenance_page_enabled_helper' => "Render Capell-managed maintenance pages instead of Laravel's default maintenance page.",
    'enable_lazy_loading' => 'Enable Lazy Loading for Images',
    'enable_static_generation' => 'Enable Static Site Generation',
    'generate_sitemap' => 'Auto-generate Sitemap',
    'languages' => 'Languages',
    'languages_info' => 'How the public site behaves for a visitor whose browser asks for a language other than the one they landed on.',
    'minify_assets' => 'Minify CSS and JavaScript',
    'minify_html' => 'Minify HTML',
    'performance' => 'Performance',
    'seconds' => 'seconds',
    'visitor_language_detection' => 'Visitor language detection',
    'visitor_language_detection_banner' => 'Suggest the visitor’s language with a dismissible banner',
    'visitor_language_detection_helper' => 'What to do when a first-time visitor’s browser asks for a language this page is not written in. Only ever applies to the exact translation of the same page; visitors are never sent to a different page.',
    'visitor_language_detection_off' => 'Do nothing',
    'visitor_language_detection_redirect' => 'Redirect to the same page in the visitor’s language',
];
