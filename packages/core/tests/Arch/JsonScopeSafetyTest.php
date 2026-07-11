<?php

declare(strict_types=1);

/**
 * Guards against the cross-database JSON-scope bug class that 404'd the Capell
 * Cloud homepage.
 *
 * `whereJsonDoesntContain('meta->key', false)` compiles to MySQL's
 * `NOT JSON_CONTAINS(meta, 'false', '$.key')`. When the `key` is absent,
 * `JSON_CONTAINS` returns NULL, `NOT NULL` is NULL, and the row is excluded —
 * even though intent is "treat absent as not-false". SQLite (used by the test
 * suite and local dev) instead includes the row, so the bug is invisible until
 * it hits a MySQL-backed install.
 *
 * The safe pattern pairs the value check with a key-presence check:
 *
 *   $query->whereNull('meta')
 *       ->orWhereJsonDoesntContainKey('meta->key')   // absent key → included
 *       ->orWhereJsonDoesntContain('meta->key', false);
 *
 * This test fails if any package `src` file uses
 * `whereJsonDoesntContain('...->key', false)` on a nested path without a
 * companion `whereJsonDoesntContainKey('...->key')` for the SAME path in the
 * same file.
 *
 * @return array<string, array{0: string}>
 */
function capellSourceFiles(): array
{
    $packagesPath = dirname(__DIR__, 3);

    if (! is_dir($packagesPath)) {
        return [];
    }

    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packagesPath, FilesystemIterator::SKIP_DOTS),
    ) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        // Only application source, never tests/vendor.
        if (! str_contains($path, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $files[$path] = [$path];
    }

    ksort($files);

    return $files;
}

/**
 * Every source file is scanned, but only files that actually call
 * `whereJsonDoesntContain()` can fail the guard below. Spawning a test case —
 * and therefore an application boot — for the ~2,000 files that cannot fail
 * costs minutes of suite time and proves nothing, so the dataset is narrowed to
 * the candidates. The narrowing itself is asserted in the test above.
 *
 * @return array<string, array{0: string}>
 */
function capellSourceFilesCallingWhereJsonDoesntContain(): array
{
    $sourceFiles = capellSourceFiles();

    $candidates = array_filter(
        $sourceFiles,
        static fn (array $arguments): bool => str_contains(
            (string) file_get_contents($arguments[0]),
            'JsonDoesntContain(',
        ),
    );

    // Pest rejects an empty dataset, so keep one (trivially passing) file when
    // no source file calls the method at all.
    return $candidates === [] ? array_slice($sourceFiles, 0, 1, preserve_keys: true) : $candidates;
}

it('discovers Capell source files', function (): void {
    $sourceFiles = capellSourceFiles();

    expect($sourceFiles)->not->toBeEmpty();

    // Guard the dataset narrowing below: no discovered source file that calls
    // whereJsonDoesntContain() may be dropped from the candidate set.
    $candidates = capellSourceFilesCallingWhereJsonDoesntContain();

    expect(array_diff_key($candidates, $sourceFiles))->toBe([]);

    foreach (array_keys($sourceFiles) as $path) {
        if (str_contains((string) file_get_contents($path), 'JsonDoesntContain(')) {
            expect($candidates)->toHaveKey($path);
        }
    }

})->group('Core');

it('does not use whereJsonDoesntContain on a nested path without a key-presence guard', function (string $sourceFile): void {
    $contents = (string) file_get_contents($sourceFile);

    // Match (or)whereJsonDoesntContain('a->b', true|false) on a nested (-> containing)
    // path. Both booleans are unsafe: "doesn't contain X" means "absent or not X",
    // but MySQL's JSON_CONTAINS returns NULL for an absent key, so the absent row is
    // wrongly excluded for either value.
    preg_match_all(
        "/(?:or)?[wW]hereJsonDoesntContain\(\s*'([^']*->[^']*)'\s*,\s*(?:true|false)\s*\)/",
        $contents,
        $matches,
    );

    $unguardedPaths = [];

    foreach ($matches[1] as $jsonPath) {
        $hasKeyGuard = str_contains($contents, sprintf("JsonDoesntContainKey('%s'", $jsonPath));

        if (! $hasKeyGuard) {
            $unguardedPaths[] = $jsonPath;
        }
    }

    expect($unguardedPaths)->toBe(
        [],
        sprintf(
            '%s calls whereJsonDoesntContain() on nested path(s) [%s] without a companion '
            . 'whereJsonDoesntContainKey() for the same path. On MySQL the absent-key row is '
            . 'wrongly excluded (JSON_CONTAINS returns NULL). Add an orWhereJsonDoesntContainKey() '
            . 'for each path.',
            $sourceFile,
            implode(', ', $unguardedPaths),
        ),
    );
})->with(capellSourceFilesCallingWhereJsonDoesntContain())->group('Core');
