<?php

declare(strict_types=1);

return [
    // The global switch cannot be overridden by category or signal policies.
    'enabled' => env('CAPELL_REPORTING_ENABLED', true),

    // Merge defaults, then category, then the exact (including dots) signal name.
    // Policy fields are enabled, transport and cooldown_seconds. Zero disables cooldown.
    'defaults' => [
        'enabled' => true,
        'transport' => 'log',
        'cooldown_seconds' => 300,
    ],
    'categories' => [],
    'signals' => [],

    // Null selects Laravel's default cache store and log channel respectively.
    // Cross-process suppression requires a shared store supporting atomic locks.
    'cache_store' => env('CAPELL_REPORTING_CACHE_STORE'),
    'log_channel' => env('CAPELL_REPORTING_LOG_CHANNEL'),

    // Opt-in name => container binding or Reporter class. The built-in "log" is reserved.
    // Core supplies no notification transports. Unavailable transports fall back to logs.
    'reporters' => [],
];
