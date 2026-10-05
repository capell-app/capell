<?php

declare(strict_types=1);

return [
    'owning_application_required' => 'Installed runtime activation requires the owning application; install outside the request sandbox and reload retained workers.',
    'failed_application' => 'Installed runtime failed; a fresh application is required before activation.',

    'application_unavailable' => 'Package activation is incomplete. A fresh application is required before serving requests.',
    'queue_workers' => 'Queue workers',
    'start' => 'Refreshing the Capell runtime',
    'passed' => 'passed',
    'failed' => 'failed',
    'skipped' => 'skipped',
    'success' => 'Capell runtime refresh completed successfully.',
    'failure' => 'Capell runtime refresh completed with failures. Review every failed stage above.',
];
