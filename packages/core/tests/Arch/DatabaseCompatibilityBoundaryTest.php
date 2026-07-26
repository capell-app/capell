<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

it('keeps driver inspection and dialect-only SQL inside database adapters', function (): void {
    $root = dirname(__DIR__, 4);
    $paths = [
        $root . '/packages/core/src',
        $root . '/packages/core/database/migrations',
        $root . '/packages/admin/src',
        $root . '/packages/installer/src',
    ];
    $violations = [];
    $driverInspection = '/(?:DB::(?:connection\\(\\)->)?getDriverName|->getDriverName)\\s*\\(/';
    $dialectSql = '/\\b(?:CONCAT|FIELD|DATE_FORMAT|JSON_EXTRACT|TIMESTAMPDIFF|STRPOS|INSTR|POSITION)\\s*\\(|\\b(?:strftime|json_extract)\\s*\\(/';

    foreach ($paths as $path) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $pathname = $file->getPathname();
            $relative = str_replace($root . '/', '', $pathname);

            if (str_contains($relative, '/Support/Database/') || str_contains($relative, '/Support/Backup/')) {
                continue;
            }

            $contents = (string) file_get_contents($pathname);

            if (preg_match($driverInspection, $contents) === 1) {
                $violations[] = $relative . ': direct driver inspection';
            }

            if (preg_match($dialectSql, $contents) === 1) {
                $violations[] = $relative . ': dialect-only SQL';
            }
        }
    }

    Assert::assertSame([], $violations, "Database compatibility boundary violations:\n" . implode("\n", $violations));
});
