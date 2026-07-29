<?php

declare(strict_types=1);

use PHPUnit\Framework\Assert;

function capellDatabaseCompatibilityStringViolation(string $value): ?string
{
    $dialectFunction = '/\\b(?:CONCAT|FIELD|DATE_FORMAT|JSON_CONTAINS|JSON_EXTRACT|JSON_SEARCH|JSON_UNQUOTE|JSON_VALUE|TIMESTAMPDIFF|STRPOS|INSTR|strftime|json_each|json_extract|json_tree|jsonb_path_query|plainto_tsquery|to_tsvector|ts_rank(?:_cd)?)\\s*\\(/i';
    $positionFunction = '/\\bposition\\s*\\([^)]*\\bin\\b[^)]*\\)/i';
    $fullTextOperator = '/\\bmatch\\s*\\([^)]*\\)\\s+against\\b|\\bUSING\\s+GIN\\b/i';
    $fullTextIndex = '/\\b(?:ADD\\s+)?FULLTEXT\\s*\\(|\\bFULLTEXT\\s+(?:INDEX|KEY)\\s+[`"]?[A-Za-z_]\\w*[`"]?\\s+(?:ON\\b|\\()/i';
    $ilikeOperator = '/(?:\\b[A-Za-z_]\\w*(?:\\.[A-Za-z_]\\w*)?\\b|[`"][^`"]+[`"])\\s+ILIKE\\s+(?:\\?|:[A-Za-z_]\\w*|\'(?:\'\'|[^\'])*\')/i';
    $databaseCatalog = '/\\b(?:information_schema|pg_catalog|sqlite_master)\\b|\\bPRAGMA\\s+|\\bSHOW\\s+INDEX\\b/i';
    $sqlKeyword = '/\\b(?:SELECT|FROM|WHERE|JOIN|ORDER|GROUP|CASE|WHEN|AS)\\b/i';

    if (preg_match($dialectFunction, $value) === 1
        || preg_match($positionFunction, $value) === 1
        || preg_match($fullTextOperator, $value) === 1
        || preg_match($fullTextIndex, $value) === 1
        || preg_match($ilikeOperator, $value) === 1
        || preg_match($databaseCatalog, $value) === 1) {
        return 'dialect-only SQL';
    }

    if (preg_match('/(?<![$.])\b[A-Za-z_]\w*\s*\|\|\s*(?:[A-Za-z_]\w*|\'[^\']*\')/', $value) === 1) {
        return 'driver-specific concatenation operator';
    }

    if (preg_match('/`[A-Za-z_][A-Za-z0-9_.]*`/', $value) === 1 && preg_match($sqlKeyword, $value) === 1) {
        return 'driver-specific identifier quoting';
    }

    return null;
}

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
                $violation = capellDatabaseCompatibilityStringViolation($string);

                if (is_string($violation)) {
                    $violations[] = $relative . ': ' . $violation;
                    break;
                }
            }
        }
    }

    Assert::assertSame([], $violations, "Database compatibility boundary violations:\n" . implode("\n", $violations));
});

it('recognizes standalone case-insensitive database fragments', function (string $fragment, string $expected): void {
    expect(capellDatabaseCompatibilityStringViolation($fragment))->toBe($expected);
})->with([
    'lowercase full text match' => [
        'match (title, body) against (? in boolean mode)',
        'dialect-only SQL',
    ],
    'standalone ILIKE' => [
        'name ilike ?',
        'dialect-only SQL',
    ],
    'standalone fulltext index' => [
        'FULLTEXT INDEX documents_search ON (title, body)',
        'dialect-only SQL',
    ],
    'standalone concatenation' => [
        'first_name || last_name',
        'driver-specific concatenation operator',
    ],
]);

it('ignores database words used in non-SQL prose', function (string $prose): void {
    expect(capellDatabaseCompatibilityStringViolation($prose))->toBeNull();
})->with([
    'fulltext prose' => 'The FULLTEXT feature is optional for this package.',
    'ilike prose' => 'This behaves ILIKE the previous implementation.',
]);
