<?php

declare(strict_types=1);

it('uses it for named Pest test definitions', function (): void {
    $violations = [];

    foreach (pestStylePhpPaths() as $path) {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines)) {
            $violations[] = sprintf('%s: could not read file', $path);

            continue;
        }

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*test\(\s*[\'\"]/', $line) !== 1) {
                continue;
            }

            $violations[] = sprintf(
                '%s:%d',
                str_replace(dirname(__DIR__, 4) . '/', '', $path),
                $index + 1,
            );
        }
    }

    expect($violations)->toBe([]);
});

/**
 * @return list<string>
 */
function pestStylePhpPaths(): array
{
    $root = dirname(__DIR__, 4);
    $paths = [];

    foreach ([$root . '/packages', $root . '/tests'] as $sourceRoot) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if ($path === __FILE__ || str_contains($path, '/vendor/')) {
                continue;
            }

            $paths[] = $path;
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}
