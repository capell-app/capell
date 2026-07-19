<?php

declare(strict_types=1);

it('keeps composer manifests and the lockfile free of llm runtime packages', function (): void {
    $root = dirname(__DIR__, 4);
    $manifestPaths = [
        $root . '/composer.json',
        ...glob($root . '/packages/*/composer.json') ?: [],
    ];
    $dependencyFields = [
        'conflict',
        'provide',
        'replace',
        'require',
        'require-dev',
        'suggest',
    ];
    $packageNames = [];

    foreach ($manifestPaths as $manifestPath) {
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (is_string($manifest['name'] ?? null)) {
            $packageNames[$manifestPath][] = $manifest['name'];
        }

        foreach ($dependencyFields as $field) {
            if (! is_array($manifest[$field] ?? null)) {
                continue;
            }

            foreach (array_keys($manifest[$field]) as $packageName) {
                if (is_string($packageName)) {
                    $packageNames[$manifestPath][] = $packageName;
                }
            }
        }
    }

    $lockPath = $root . '/composer.lock';
    $lock = json_decode(
        (string) file_get_contents($lockPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    foreach (['packages', 'packages-dev'] as $section) {
        foreach ($lock[$section] ?? [] as $package) {
            if (is_array($package) && is_string($package['name'] ?? null)) {
                $packageNames[$lockPath . '#' . $section][] = $package['name'];
            }
        }
    }

    $violations = [];

    foreach ($packageNames as $source => $names) {
        foreach ($names as $packageName) {
            if (preg_match('~(anthropic|openai|prism|gemini|langchain)~i', $packageName) === 1) {
                $violations[] = sprintf('%s (%s)', $packageName, str_replace($root . '/', '', $source));
            }
        }
    }

    sort($violations);

    expect(array_slice($manifestPaths, 1))->not->toBeEmpty()
        ->and($violations)->toBeEmpty();
});
