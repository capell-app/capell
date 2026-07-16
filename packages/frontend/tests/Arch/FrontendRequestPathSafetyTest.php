<?php

declare(strict_types=1);

it('keeps forever caches out of frontend production code', function (): void {
    $violations = [];

    foreach (frontendProductionPhpPaths() as $path) {
        $contents = (string) file_get_contents($path);

        foreach (frontendForeverCachePatterns() as $pattern) {
            if (preg_match($pattern, $contents) !== 1) {
                continue;
            }

            $violations[] = str_replace(dirname(__DIR__, 4) . '/', '', $path);

            break;
        }
    }

    expect($violations)->toBe([]);
});

/**
 * @return list<string>
 */
function frontendProductionPhpPaths(): array
{
    $root = dirname(__DIR__, 2) . '/src';
    $paths = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $paths[] = $file->getPathname();
    }

    sort($paths);

    return $paths;
}

/**
 * @return list<string>
 */
function frontendForeverCachePatterns(): array
{
    return [
        '/\brememberForever\s*\(/',
        '/(?:->|::)forever\s*\(/',
    ];
}
