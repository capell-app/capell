<?php

declare(strict_types=1);

return [
    'command' => [
        'runs' => 'Changes applied: :applied; failed: :failed.',
        'retired' => 'Node :node was retired from the security fleet.',
        'registry_unknown' => 'The security node registry is unavailable or invalid; review the fleet before continuing.',
        'refresh_single_node' => 'This installation is not multi-node; there is nothing to refresh.',
        'refresh_description' => 'Apply approved security changes on this node and report it to the rest of the fleet.',
        'refresh_no_acknowledgement' => 'No pending changes were acknowledged on this node.',
        'refresh_node' => 'Node :node refreshed :acknowledged pending change(s); :completed change(s) finished.',
    ],
    'doctor' => [
        'label' => 'Security posture',
        'message' => 'Open security findings: :critical critical, :high high.',
        'action' => 'Run php artisan capell:security:scan and review the findings.',
        'node_collision' => 'Multiple hosts have registered security node :node; give each host a unique node ID.',
        'unknown_fleet' => 'The node membership for remediation run :run is unknown; review it before settling the change.',
        'pending_nodes' => ':count approved change(s) are waiting for these nodes to apply them: :nodes. Check that the scheduler runs on every node.',
    ],
    'remediation' => [
        'approval_revoked' => 'Approval for this change was withdrawn or no longer matches the fix.',
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
        'pending_nodes' => 'Applied on this node; waiting for :count other node(s) to apply it.',
        'pending_nodes_skip' => 'A change for this finding is still being applied on other nodes.',
        'nodes_not_refreshed' => 'Not applied on every node. These nodes did not refresh: :nodes.',
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
