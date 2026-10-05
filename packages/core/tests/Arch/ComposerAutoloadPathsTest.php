<?php

declare(strict_types=1);

it('maps every root and package Composer autoload entry to an existing path', function (): void {
    $root = dirname(__DIR__, 4);
    $manifestPaths = [
        $root . '/composer.json',
        ...(glob($root . '/packages/*/composer.json') ?: []),
    ];
    $missing = [];
    $checked = 0;

    expect(count($manifestPaths))->toBeGreaterThan(1);

    foreach ($manifestPaths as $manifestPath) {
        $composer = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $relativeManifest = substr($manifestPath, strlen($root) + 1);

        throw_unless(is_array($composer), LogicException::class, sprintf('Expected %s to be an object.', $relativeManifest));

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $composer[$section] ?? [];

            throw_unless(is_array($autoload), LogicException::class, sprintf('Expected %s %s to be an object.', $relativeManifest, $section));

            foreach (['psr-4', 'classmap', 'files'] as $type) {
                $entries = $autoload[$type] ?? [];

                throw_unless(is_array($entries), LogicException::class, sprintf('Expected %s %s.%s to contain paths.', $relativeManifest, $section, $type));

                foreach ($entries as $key => $entry) {
                    $paths = is_array($entry) ? $entry : [$entry];

                    foreach ($paths as $path) {
                        throw_unless(is_string($path), LogicException::class, sprintf('Expected %s %s.%s [%s] to contain string paths.', $relativeManifest, $section, $type, $key));

                        $absolutePath = str_starts_with($path, '/') ? $path : dirname($manifestPath) . '/' . $path;
                        $exists = match ($type) {
                            'psr-4' => is_dir($absolutePath),
                            'files' => is_file($absolutePath),
                            // Composer supports wildcard paths for classmaps.
                            'classmap' => array_filter(
                                glob($absolutePath, GLOB_BRACE) ?: [],
                                file_exists(...),
                            ) !== [],
                        };

                        if (! $exists) {
                            $missing[] = sprintf('%s %s.%s [%s] => %s', $relativeManifest, $section, $type, $key, $path);
                        }

                        $checked++;
                    }
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($missing)->toBe([], implode("\n", $missing));
})->group('Core');
