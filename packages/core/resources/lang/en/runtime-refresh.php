<?php

declare(strict_types=1);

return [
    'owning_application_required' => 'Installed runtime activation requires the owning application; install outside the request sandbox and reload retained workers.',
    'failed_application' => 'Installed runtime failed; a fresh application is required before activation.',

    'application_unavailable' => 'This surface is unavailable after a runtime registration failure. Review the application log and runtime diagnostics, repair the cause, then start a fresh application or reload retained workers.',
    'cache_refresh_required' => 'Package activation is incomplete: clear the Laravel route and configuration caches, then reload retained workers before using the new surfaces.',
    'diagnostic_label' => 'Installed runtime failures',
    'diagnostic_ok' => 'No installed runtime failures in this application.',
    'diagnostic_failed' => 'Installed runtime registration failed for: :packages.',
    'diagnostic_remediation' => 'Review the original exception in the application log, repair the package, then retry in a fresh application. Reload Octane and restart retained workers and schedulers.',
    'retained_reload_required' => 'Installation is recorded and local caches are cleared. Activation still requires a cache refresh on every other node and a reload of Octane and retained scheduler processes; the new surfaces are not yet confirmed reachable there.',
    'queue_workers' => 'Queue workers',
    'start' => 'Refreshing the Capell runtime',
    'passed' => 'passed',
    'failed' => 'failed',
    'skipped' => 'skipped',
    'success' => 'Capell runtime refresh completed successfully.',
    'failure' => 'Capell runtime refresh completed with failures. Review every failed stage above.',
];
