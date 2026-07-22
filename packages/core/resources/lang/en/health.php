<?php

declare(strict_types=1);

return [
    'cache' => [
        'enabled' => [
            'label' => 'Core cache enabled',
            'passed' => 'The Capell core cache is enabled.',
            'failed' => 'The Capell core cache is disabled.',
            'remediation' => 'Set CAPELL_DISABLE_CACHE=false and rebuild the application configuration cache.',
        ],
        'backend' => [
            'label' => 'Core cache backend',
            'passed' => 'The :store cache store (:driver) accepted a write/read/delete probe.',
            'failed' => 'The :store cache store (:driver) did not complete a write/read/delete probe.',
            'remediation' => 'Check the configured cache service and credentials, then rerun the Capell health checks.',
        ],
    ],
];
