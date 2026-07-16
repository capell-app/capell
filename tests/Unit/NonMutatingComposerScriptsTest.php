<?php

declare(strict_types=1);

it('keeps documented Composer check scripts recursively non-mutating', function (string $script): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $scripts = $composer['scripts'] ?? [];

    expect($scripts)->toHaveKey($script);

    foreach (expandComposerCheckScript($script, $scripts) as $command) {
        $normalized = strtolower($command);

        if (str_contains($normalized, 'vendor/bin/rector')) {
            expect($normalized)->toContain('--dry-run');
        }

        if (str_contains($normalized, 'vendor/bin/pint')) {
            expect($normalized)->toContain('--test');
        }

        if (str_contains($normalized, 'prettier')) {
            expect($normalized)->not->toContain('--write');
        }

        expect($normalized)
            ->not->toContain('rector process')
            ->not->toContain('capell:install')
            ->not->toContain('migrate:fresh');
    }
})->with(['preflight']);

it('applies Rector transformations during the full preflight', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $scripts = $composer['scripts'] ?? [];
    $commands = expandComposerCheckScript('preflight:all', $scripts);
    $rectorCommands = array_values(array_filter(
        $commands,
        static fn (string $command): bool => str_contains(strtolower($command), 'vendor/bin/rector'),
    ));

    expect($rectorCommands)
        ->toHaveCount(1)
        ->and(strtolower($rectorCommands[0]))
        ->not->toContain('--dry-run');
});

/**
 * @param  array<string, string|list<string>>  $scripts
 * @param  array<string, bool>  $visiting
 * @return list<string>
 */
function expandComposerCheckScript(string $name, array $scripts, array $visiting = []): array
{
    if (isset($visiting[$name])) {
        throw new RuntimeException(sprintf('Composer script alias cycle detected at [%s].', $name));
    }

    $visiting[$name] = true;
    $entries = $scripts[$name] ?? [];
    $entries = is_array($entries) ? $entries : [$entries];

    $commands = [];

    foreach ($entries as $entry) {
        if (! is_string($entry)) {
            continue;
        }

        if (preg_match('/^@([a-z0-9:._-]+)$/i', $entry, $matches) === 1 && array_key_exists($matches[1], $scripts)) {
            $commands = [...$commands, ...expandComposerCheckScript($matches[1], $scripts, $visiting)];

            continue;
        }

        $commands[] = $entry;
    }

    return $commands;
}
