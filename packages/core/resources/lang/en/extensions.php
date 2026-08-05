<?php

declare(strict_types=1);

return [
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
