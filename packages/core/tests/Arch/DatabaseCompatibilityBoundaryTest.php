<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

it('keeps driver inspection and dialect-only SQL inside database adapters', function (): void {
    $root = dirname(__DIR__, 4);
    $paths = [];

    foreach (glob($root . '/packages/*', GLOB_ONLYDIR) ?: [] as $packagePath) {
        foreach ([$packagePath . '/src', $packagePath . '/database/migrations'] as $productionPath) {
            if (is_dir($productionPath)) {
                $paths[] = $productionPath;
            }
        }
    }

    $violations = [];
    $driverInspection = '/(?:DB::(?:connection\\(\\)->)?getDriverName|->getDriverName)\\s*\\(/';
    $dialectSql = '/\\b(?:CONCAT|FIELD|DATE_FORMAT|JSON_CONTAINS|JSON_EXTRACT|JSON_SEARCH|JSON_UNQUOTE|JSON_VALUE|TIMESTAMPDIFF|STRPOS|INSTR|POSITION|MATCH|strftime|json_each|json_extract|json_tree|jsonb_path_query|plainto_tsquery|to_tsvector|ts_rank(?:_cd)?)\\s*\\(/';
    $dialectOperator = '/\\b(?:AGAINST|FULLTEXT|ILIKE)\\b|\\bUSING\\s+GIN\\b/i';
    $databaseCatalog = '/\\b(?:information_schema|pg_catalog|sqlite_master)\\b|\\bPRAGMA\\s+|\\bSHOW\\s+INDEX\\b/i';
    $sqlKeyword = '/\\b(?:SELECT|FROM|WHERE|JOIN|ORDER|GROUP|CASE|WHEN|AS)\\b/i';

    foreach ($paths as $path) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $pathname = $file->getPathname();
            $relative = str_replace($root . '/', '', $pathname);
            if (str_starts_with($relative, 'packages/core/src/Support/Database/Platforms/')) {
                continue;
            }

            if (str_starts_with($relative, 'packages/core/src/Support/Database/Provisioners/')) {
                continue;
            }

            if (str_starts_with($relative, 'packages/core/src/Support/Database/QueryDialects/')) {
                continue;
            }

            if (str_starts_with($relative, 'packages/core/src/Support/Database/SchemaDialects/')) {
                continue;
            }

            if (in_array($relative, [
                'packages/core/src/Support/Database/DatabasePlatformRegistry.php',
                'packages/core/src/Support/Database/FullTextIndexCompatibilityCache.php',
            ], true)) {
                continue;
            }

            $contents = (string) file_get_contents($pathname);

            if (preg_match($driverInspection, $contents) === 1) {
                $violations[] = $relative . ': direct driver inspection';
            }

            foreach (token_get_all($contents) as $token) {
                if (! is_array($token)) {
                    continue;
                }

                if (! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                $string = $token[1];

                if (preg_match($dialectSql, $string) === 1
                    || (preg_match($dialectOperator, $string) === 1 && preg_match($sqlKeyword, $string) === 1)
                    || preg_match($databaseCatalog, $string) === 1) {
                    $violations[] = $relative . ': dialect-only SQL';
                    break;
                }

                if (str_contains($string, '||') && preg_match($sqlKeyword, $string) === 1) {
                    $violations[] = $relative . ': driver-specific concatenation operator';
                    break;
                }

                if (preg_match('/`[A-Za-z_][A-Za-z0-9_.]*`/', $string) === 1 && preg_match($sqlKeyword, $string) === 1) {
                    $violations[] = $relative . ': driver-specific identifier quoting';
                    break;
                }
            }
        }
    }

    Assert::assertSame([], $violations, "Database compatibility boundary violations:\n" . implode("\n", $violations));
});
