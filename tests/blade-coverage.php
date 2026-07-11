<?php

declare(strict_types=1);

return [
    'include' => [
        'packages/*/resources/views/**/*.blade.php',
    ],
    'exclude' => [],
    'baseline' => 'tests/BladeCoverage/baseline.json',
    'cache' => '.cache/pest-blade-coverage',
];
