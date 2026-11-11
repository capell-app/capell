<?php

declare(strict_types=1);

return [
    'extension_verification' => [
        'label' => 'Extension verification',
        'passed' => 'Installed extensions passed verification.',
        'failed' => 'Some installed extensions need attention: :issues',
    ],
    'migrations' => [
        'label' => 'Migrations are up to date',
        'passed' => 'All migrations have run and every required table exists.',
        'pending' => ':count migration(s) have not run yet, for example :examples. The tables they create or change are missing or out of date until they run.',
        'pending_remediation' => 'Run php artisan migrate.',
        'missing_tables' => 'These required tables do not exist although no migration is pending: :tables. Capell cannot read or save content without them.',
        'missing_tables_remediation' => 'The Capell migrations have not been published to this application. Run php artisan capell:install to publish and run them.',
    ],
    'install_lifecycle' => [
        'label' => 'Installation is complete',
        'passed' => 'The installer recorded a complete installation.',
        'passed_install_summary' => 'The installer has not yet recorded its final step; this summary runs before it.',
        'not_installed' => 'Capell has not been installed in this application. Nothing in the admin or on the site will work until it is.',
        'not_installed_remediation' => 'Run php artisan capell:install.',
        'stopped' => 'Installation stopped at ":step" (:key). Capell is only partly installed, so the admin and the site may not work yet.',
        'stopped_remediation' => 'Resume the installation with: :command',
        'unrecorded' => 'The installation is incomplete, but the installer did not record which step stopped. Capell may be only partly set up.',
        'unrecorded_remediation' => 'Run php artisan capell:install to finish the installation. Add --plan first to see the steps.',
    ],
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
