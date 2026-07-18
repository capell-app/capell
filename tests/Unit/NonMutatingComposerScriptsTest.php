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
    $runner = file_get_contents(dirname(__DIR__, 2) . '/scripts/run-preflight.php');

    expect($scripts['preflight:all'])
        ->toContain('@php scripts/run-preflight.php --all')
        ->and($runner)->toContain("'rector' => 'rector:all'")
        ->and($runner)->toContain("'rector' => 'rector:all:check'");
});

it('continues after a failed preflight gate and returns a final failure', function (): void {
    $root = dirname(__DIR__, 2);
    $temporary = sys_get_temp_dir() . '/capell-preflight-runner-' . bin2hex(random_bytes(6));
    mkdir($temporary, recursive: true);

    $composer = $temporary . '/composer';
    $log = $temporary . '/calls.log';

    file_put_contents($composer, <<<'BASH'
#!/usr/bin/env bash
echo "$1" >> "$CAPELL_PREFLIGHT_TEST_LOG"
if [ "$1" = "analyze" ]; then
    exit 17
fi
BASH);
    chmod($composer, 0755);

    $command = sprintf(
        'COMPOSER_BINARY=%s CAPELL_PREFLIGHT_TEST_LOG=%s %s %s phpstan tests 2>&1',
        escapeshellarg($composer),
        escapeshellarg($log),
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/scripts/run-preflight.php'),
    );

    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(1)
        ->and(file($log, FILE_IGNORE_NEW_LINES))->toBe(['analyze', 'test:preflight'])
        ->and(implode("\n", $output))
        ->toContain('FAIL phpstan')
        ->toContain('PASS tests')
        ->toContain('Preflight failed: phpstan');

    unlink($log);
    unlink($composer);
    rmdir($temporary);
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
