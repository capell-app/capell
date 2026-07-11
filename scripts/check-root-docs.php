<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$allowed = [
    'CHANGELOG.md',
    'CODE_OF_CONDUCT.md',
    'CONTRIBUTING.md',
    'LICENSE.md',
    'README.md',
    'SECURITY.md',
];

$allowedLookup = array_fill_keys($allowed, true);
$failures = [];

foreach (glob($root . '/*.md') ?: [] as $path) {
    $fileName = basename($path);

    if (! isset($allowedLookup[$fileName])) {
        $failures[] = $fileName;
    }
}

if ($failures !== []) {
    sort($failures);

    fwrite(STDERR, "Unexpected root markdown file(s):\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }

    fwrite(STDERR, "\nMove scratch/handoff notes outside the repo or add permanent docs under docs/.\n");

    return 2;
}
