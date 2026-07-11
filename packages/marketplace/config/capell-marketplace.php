<?php

declare(strict_types=1);

return [
    'enabled' => env('CAPELL_MARKETPLACE_ENABLED', true),
    'instance' => [
        'id' => env('CAPELL_INSTANCE_ID'),
    ],
    'marketplace' => [
        'base_url' => env('CAPELL_MARKETPLACE_URL', 'https://capell.app/api/v1'),
        'web_url' => env('CAPELL_MARKETPLACE_WEB_URL', 'https://capell.app'),
        'timeout_seconds' => 10,
        'telemetry_timeout_seconds' => 3,
        'cache_ttl_seconds' => 300,
        'stale_cache_ttl_seconds' => 3600,
        'warm_throttle_seconds' => 60,
        'catalogue_page_limit' => env('CAPELL_MARKETPLACE_CATALOGUE_PAGE_LIMIT', 3),
        'webhook_url' => env('CAPELL_MARKETPLACE_WEBHOOK_URL'),
        'webhook_secret' => env('CAPELL_MARKETPLACE_WEBHOOK_SECRET'),
        'troubleshooting_url' => env('CAPELL_MARKETPLACE_TROUBLESHOOTING_URL', 'https://docs.capell.app/extensions/marketplace-heartbeat'),
    ],
];
