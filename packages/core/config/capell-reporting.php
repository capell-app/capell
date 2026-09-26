<?php

declare(strict_types=1);

return [
    // The global switch cannot be overridden by category or signal policies.
    'enabled' => env('CAPELL_REPORTING_ENABLED', true),

    // Merge defaults, then category, then the exact (including dots) signal name.
    // Zero disables cooldown. The operator transport opts into the listed channels.
    'defaults' => [
        'enabled' => true,
        'transport' => 'log',
        'cooldown_seconds' => 300,
        'channels' => ['log'],
        'owner' => null,
        'backup' => null,
        'escalate_after_seconds' => 900,
    ],
    'categories' => [],
    'signals' => [],

    // Null selects Laravel's default cache store and log channel respectively.
    // Cross-process suppression requires a shared store supporting atomic locks.
    'cache_store' => env('CAPELL_REPORTING_CACHE_STORE'),
    'log_channel' => env('CAPELL_REPORTING_LOG_CHANNEL'),

    // Operator aliases are private configuration, never part of a signal payload.
    'operators' => [],
    'email' => [
        'enabled' => false,
        'mailer' => null,
        'max_attempts' => 5,
        'window_seconds' => 3600,
    ],
    'health' => ['enabled' => false],

    // Opt-in name => container binding or Reporter class. "log" and "operator" are reserved.
    // Unavailable transports fall back to logs.
    'reporters' => [],
];
