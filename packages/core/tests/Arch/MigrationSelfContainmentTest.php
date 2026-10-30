<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('keeps Core migrations from 1 October 2026 self-contained', function (): void {
    $migrationDirectory = dirname(__DIR__, 2) . '/database/migrations';
    $violations = [];

    foreach (File::files($migrationDirectory) as $file) {
        if (substr($file->getFilename(), 0, 10) < '2026_10_01') {
            continue;
        }

        // TableName is the one sanctioned dependency: it resolves the configurable table names (CAP-2191).
        $contents = (string) preg_replace('/(use Capell\\\\Core\\\\Support\\\\TableName;|\\bTableName::[A-Za-z]+\\()/', '', File::get($file->getPathname()));

        foreach (['use Capell\\', 'app(', 'config(', 'resolve('] as $forbidden) {
            if (str_contains($contents, $forbidden)) {
                $violations[] = $file->getFilename() . ' contains [' . $forbidden . ']';
            }
        }
    }

    expect($violations)->toBe([]);
});
