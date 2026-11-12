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
        'stopped_password_note' => 'The first run was given a password, which is never saved. Add --password=<value> to this command before you run it.',
        'unrecorded' => 'The installation is incomplete, but the installer did not record which step stopped. Capell may be only partly set up.',
        'unrecorded_remediation' => 'Run php artisan capell:install to finish the installation. Add --plan first to see the steps.',
    ],
    'doctor' => [
        'warning_summary' => '{0} No checks reported warnings.|{1} :count check reported a warning. The site still works, but the warning is worth fixing. To list it, run: :command|[2,*] :count checks reported warnings. The site still works, but the warnings are worth fixing. To list them, run: :command',
        'handoff_pointer' => 'To list the warnings, run: :command',
        'all_passed' => 'All checks passed.',
        'passed_with_warnings' => 'Checks passed with warnings. The site works; fix the warnings when you can.',
        'failed' => 'One or more checks failed. See suggestions above.',
        'no_warnings_to_list' => 'No checks reported warnings.',
        'runtime_stage_passed' => 'All Capell Doctor checks passed.',
        'runtime_stage_passed_with_warnings' => 'Capell Doctor passed with :count warning(s) that do not stop the site working. To list them, run: :command',
        'runtime_stage_failed' => 'Failed checks: :checks.',
        'theme_passed' => 'All theme checks passed.',
        'theme_passed_with_warnings' => 'Theme checks passed with warnings. The theme works; fix the warnings when you can.',
        'theme_failed' => 'One or more theme checks failed. See suggestions above.',
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
    'hosting_platform' => [
        'label' => 'Hosting platform',
        'platforms' => [
            'laravel-cloud' => 'Laravel Cloud',
            'self-managed' => 'a self-managed host',
        ],
        'summary' => 'Capell is running on :platform with CAPELL_RELEASE_ROOT_MODE=:mode and QUEUE_CONNECTION=:connection.',
        'mutable_release_root' => 'CAPELL_RELEASE_ROOT_MODE is mutable on Laravel Cloud. Each deploy resets the filesystem and every replica has its own copy, so anything installed or rebuilt from the admin is lost or reaches only one replica.',
        'mutable_release_root_remediation' => 'Set CAPELL_RELEASE_ROOT_MODE=immutable and install extensions and build assets in the build commands instead.',
        'sync_queue' => 'QUEUE_CONNECTION is :connection, which runs every job inside the web request. Slow jobs delay or time out the request that triggered them, and failed jobs are not retried.',
        'sync_queue_remediation' => 'Use a worker-backed queue connection, such as a managed queue on Laravel Cloud or database or Redis with a running queue worker.',
        'scheduler_heartbeat_missing' => 'The scheduler has not recorded a heartbeat. Scheduled publishing, pruning and retries do not run while the scheduler is stopped. Capell records a heartbeat every five minutes, so a scheduler started in the last five minutes may not have recorded one yet.',
        'scheduler_heartbeat_stale' => 'The scheduler last recorded a heartbeat at :time. Scheduled publishing, pruning and retries do not run while the scheduler is stopped.',
        'scheduler_remediation' => 'Run php artisan schedule:run every minute. On Laravel Cloud, turn on the Scheduler option for the App cluster and redeploy.',
        'recipe' => 'See :url for the Laravel Cloud recipe.',
    ],
];
