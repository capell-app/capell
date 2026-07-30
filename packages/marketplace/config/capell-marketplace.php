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
        // Outbound retry policy applied only to idempotent marketplace catalogue and
        // extension reads. Signed writes (connection and install-flow code exchanges,
        // install and upgrade authorizations, feedback, telemetry, heartbeat) are
        // deliberately never retried: those are single-use or state-creating.
        'read_retry' => [
            'retry_times' => env('CAPELL_MARKETPLACE_READ_RETRY_TIMES', 3),
            'retry_delay_ms' => env('CAPELL_MARKETPLACE_READ_RETRY_DELAY_MS', 500),
            'retry_after_max_ms' => env('CAPELL_MARKETPLACE_READ_RETRY_AFTER_MAX_MS', 60000),
        ],
        'cache_ttl_seconds' => 300,
        'stale_cache_ttl_seconds' => 3600,
        'warm_throttle_seconds' => 60,
        'operations_queue_connection' => env('CAPELL_MARKETPLACE_QUEUE_CONNECTION', 'database'),
        'operations_queue' => env('CAPELL_MARKETPLACE_QUEUE', 'capell-marketplace'),
        'catalogue_page_limit' => env('CAPELL_MARKETPLACE_CATALOGUE_PAGE_LIMIT', 3),
        'webhook_url' => env('CAPELL_MARKETPLACE_WEBHOOK_URL'),
        'webhook_secret' => env('CAPELL_MARKETPLACE_WEBHOOK_SECRET'),
        'troubleshooting_url' => env('CAPELL_MARKETPLACE_TROUBLESHOOTING_URL', 'https://docs.capell.app/extensions/marketplace-heartbeat'),
    ],
];
