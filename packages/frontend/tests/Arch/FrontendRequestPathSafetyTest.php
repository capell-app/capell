<?php

declare(strict_types=1);

it('keeps forever caches out of frontend production code', function (): void {
    // The rule exists to stop rendered frontend output being cached without
    // expiry, where a stale entry is served to visitors indefinitely. Entries
    // below store operational state rather than response content, so the
    // staleness hazard the rule guards against does not apply.
    //
    // TODO: InvalidateDueScheduledPublicationCachesAction persists its
    // checkpoint through the cache, which any cache flush discards. If that
    // checkpoint must genuinely survive, durable storage (settings/database) is
    // the correct home for it — tracked separately, deliberately not changed
    // alongside a performance release.
    $allowedForeverCaches = [
        // Stores the last scheduled-publication invalidation checkpoint (a unix
        // timestamp), not page or response content.
        'packages/frontend/src/Actions/InvalidateDueScheduledPublicationCachesAction.php',
    ];

    $violations = [];

    foreach (frontendProductionPhpPaths() as $path) {
        $contents = (string) file_get_contents($path);
        $relativePath = str_replace(dirname(__DIR__, 4) . '/', '', $path);

        if (in_array($relativePath, $allowedForeverCaches, true)) {
            continue;
        }

        foreach (frontendForeverCachePatterns() as $pattern) {
            if (preg_match($pattern, $contents) !== 1) {
                continue;
            }

            $violations[] = $relativePath;

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
        if (! $file->isFile()) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
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
