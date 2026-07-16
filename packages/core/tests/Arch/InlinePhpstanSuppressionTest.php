<?php

declare(strict_types=1);

it('requires inline PHPStan suppressions to name an error and explain the constraint', function (): void {
    $violations = [];

    foreach (phpstanSuppressionPhpPaths() as $path) {
        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if (! is_array($lines)) {
            $violations[] = sprintf('%s: could not read file', $path);

            continue;
        }

        foreach ($lines as $index => $line) {
            if (! str_contains($line, '@phpstan-ignore')) {
                continue;
            }

            if (preg_match('/@phpstan-ignore(?:-next-line)?\s+[a-z][A-Za-z0-9_.-]*(?:\s*,\s*[a-z][A-Za-z0-9_.-]*)*\s+\(.{12,}\)/', $line) === 1) {
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
function phpstanSuppressionPhpPaths(): array
{
    $root = dirname(__DIR__, 4);
    $paths = [];

    foreach ([$root . '/packages', $root . '/tests', $root . '/scripts'] as $sourceRoot) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if ($path === __FILE__) {
                continue;
            }

            if (str_contains((string) $path, '/vendor/')) {
                continue;
            }

            $paths[] = $path;
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}
