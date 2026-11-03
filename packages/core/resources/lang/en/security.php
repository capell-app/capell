<?php

declare(strict_types=1);

return [
    'registry' => ['owner_conflict' => 'Security weakness :id belongs to :owner and cannot be replaced by :replacement.'],
    'command' => ['scan_id' => 'Scan ID: :id', 'runs' => 'Changes applied: :applied; failed: :failed.'],
    'doctor' => [
        'label' => 'Security posture',
        'never_scanned' => 'No security scan has completed successfully. Run php artisan capell:security:scan.',
        'failed_scan' => 'The security scan finished with failures at :time. Run the scan again.',
        'message' => 'Open security findings: :critical critical, :high high.',
        'action' => 'Run php artisan capell:security:scan and review the findings.',
    ],
    'remediation' => [
        'no_fix' => 'No automatic fix is registered for security weakness :id.',
        'approval_requires_finding' => 'Scan first and approve one open finding with this weakness ID.',
        'approval_required' => 'This finding needs its own approval.',
        'evidence_changed' => 'The evidence changed since approval; review and approve the finding again.',
        'advice_only' => 'Analyser findings are advice only.',
        'not_confirmed' => 'Not confirmed by the latest scan.',
        'fix_changed' => 'The fix changed since it was approved; approve it again.',
        'env_ambiguous' => 'The environment file needs manual review before changing this setting.',
        'env_locked' => 'The environment file is being changed; try again later.',
        'started' => 'The approved change is starting.',
        'failed' => 'The change could not be completed. Review the installation settings.',
        'already_set' => 'The settings already have the required values.',
        'changed' => 'The settings were updated.',
        'cache_refreshed' => 'The configuration cache was cleared.',
        'cache_refresh_required' => 'The settings were updated; clear the configuration cache before they take effect.',
        'debug' => 'Turn off debug mode.',
        'cookie' => 'Require secure and HTTP-only session cookies.',
        'updates' => 'Allow Capell to take security releases for this package automatically.',
        'https_required' => 'Configure HTTPS before changing cookie settings.',
        'no_update_needed' => 'The package is missing or its update policy has already changed.',
    ],
    'app' => [
        'debug_enabled' => [
            'title' => 'Debug mode is enabled',
            'summary' => 'Error pages may reveal sensitive information to visitors.',
            'action' => 'Turn off debug mode and refresh the configuration cache.',
        ],
        'url_not_https' => [
            'title' => 'The site URL does not use HTTPS',
            'summary' => 'Information sent to the site may be exposed in transit.',
            'action' => 'Configure HTTPS and update the site URL.',
        ],
        'key_missing' => [
            'title' => 'The application key is missing',
            'summary' => 'The site cannot safely encrypt protected information.',
            'action' => 'Ask an administrator to configure the application key.',
        ],
    ],
    'session' => [
        'insecure_cookie' => [
            'title' => 'Session cookies are not fully protected',
            'summary' => 'A session may be exposed to scripts or an insecure connection.',
            'action' => 'Enable secure and HTTP-only cookies after configuring HTTPS.',
        ],
    ],
    'installer' => [
        'still_enabled' => [
            'title' => 'The installer is still enabled',
            'summary' => 'Installation controls may remain accessible after setup.',
            'action' => 'Disable or remove the installer after confirming setup is complete.',
        ],
    ],
    'extension' => [
        'security_advisory' => [
            'title' => 'A package has a security advisory',
            'summary' => 'An installed package has a reported security weakness.',
            'action' => 'Review the advisory and install a fixed release.',
        ],
        'auto_updates_off_for_advisory' => [
            'title' => 'Security updates are not automatic',
            'summary' => 'A package with a security advisory may remain vulnerable until it is updated.',
            'action' => 'Review the advisory and choose an appropriate update policy.',
        ],
    ],
];
