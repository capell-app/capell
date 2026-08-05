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
        /*
         * How this host reaches the network. Applied to every outbound
         * marketplace call: a proxy URL, and either a path to a CA bundle or a
         * boolean for hosts whose TLS is terminated by an appliance.
         */
        'http' => [
            'proxy' => env('CAPELL_MARKETPLACE_HTTP_PROXY'),
            'verify' => env('CAPELL_MARKETPLACE_HTTP_VERIFY'),
        ],
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
        /*
         * How long an operation may sit in the queue before the operator is told
         * that nothing is consuming it. A queued install that no worker ever
         * claims produces no error of its own, so this is the only signal there
         * is. Two minutes leaves room for a busy worker to finish the job in
         * front without accusing a healthy host of being broken.
         */
        'queued_stale_after_seconds' => env('CAPELL_MARKETPLACE_QUEUED_STALE_AFTER_SECONDS', 120),
        /*
         * How long a recorded worker heartbeat still counts as evidence that a
         * worker is consuming the Marketplace queue. The scheduled probe runs
         * every minute, so this tolerates a handful of missed runs before the
         * readiness report stops claiming a worker is there.
         */
        'worker_heartbeat_stale_after_seconds' => env('CAPELL_MARKETPLACE_WORKER_HEARTBEAT_STALE_AFTER_SECONDS', 300),
        'catalogue_page_limit' => env('CAPELL_MARKETPLACE_CATALOGUE_PAGE_LIMIT', 3),
        'webhook_url' => env('CAPELL_MARKETPLACE_WEBHOOK_URL'),
        'webhook_secret' => env('CAPELL_MARKETPLACE_WEBHOOK_SECRET'),
        'troubleshooting_url' => env('CAPELL_MARKETPLACE_TROUBLESHOOTING_URL', 'https://docs.capell.app/extensions/marketplace-heartbeat'),
    ],
];
