<?php

declare(strict_types=1);

return [
    'migration_cleanup_blocked' => 'Uninstall is incomplete because these published migrations could not be removed: :paths. Correct their filesystem permissions, then retry: :command. Uninstall hooks, data deletion and package removal have not run.',
    'auto_update_policies' => [
        'none' => 'Never update automatically',
        'patch' => 'Patch releases only',
        'minor' => 'Patch and minor releases',
        'security' => 'Security releases only',
    ],
    'release_kinds' => [
        'patch' => 'Patch release',
        'minor' => 'Minor release',
        'major' => 'Major release',
        'unknown' => 'Unknown release',
    ],
];
