<?php

declare(strict_types=1);
use Symfony\Component\Process\Process;

it('keeps documented Composer check scripts recursively non-mutating', function (string $script): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $scripts = $composer['scripts'] ?? [];

    expect($scripts)->toHaveKey($script);

    foreach (expandComposerCheckScript($script, $scripts) as $command) {
        $normalized = strtolower($command);

        // These flags prevent advertised check commands from rewriting a checkout.
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

it('applies Rector transformations only in the full preflight', function (bool $all): void {
    $directory = sys_get_temp_dir() . '/capell-rector-preflight-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $composer = $directory . '/composer';
    $fixture = $directory . '/fixture';
    file_put_contents($fixture, 'original');
    file_put_contents($composer, <<<'PHP'
        #!/usr/bin/env php
        <?php
        $scripts = json_decode(file_get_contents(getenv('CAPELL_PREFLIGHT_MANIFEST')), true, flags: JSON_THROW_ON_ERROR)['scripts'];
        $command = $scripts[$argv[1]];
        if (! str_contains($command, '--dry-run')) {
            file_put_contents(getenv('CAPELL_PREFLIGHT_FIXTURE'), 'formatted');
        }
        PHP);
    chmod($composer, 0755);
    $process = new Process([
        PHP_BINARY, dirname(__DIR__, 2) . '/scripts/run-preflight.php',
        ...($all ? ['--all'] : []), 'rector',
    ], dirname(__DIR__, 2), [
        'COMPOSER_BINARY' => $composer,
        'CAPELL_PREFLIGHT_MANIFEST' => dirname(__DIR__, 2) . '/composer.json',
        'CAPELL_PREFLIGHT_FIXTURE' => $fixture,
    ]);
    // Hang ceiling only: a busy host must slow this down, never fail it.
    $process->setTimeout(300);
    try {
        expect($process->run())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toContain('PASS rector')
            ->and(file_get_contents($fixture))->toBe($all ? 'formatted' : 'original');
    } finally {
        unlink($composer);
        unlink($fixture);
        rmdir($directory);
    }
})->with(['check' => false, 'full' => true]);
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
        ->and(file($log, FILE_IGNORE_NEW_LINES))->toEqualCanonicalizing(['analyze', 'test:preflight'])
        ->and(implode("\n", $output))
        ->toContain('FAIL phpstan')
        ->toContain('PASS tests')
        ->toContain('Preflight failed: phpstan');

    unlink($log);
    unlink($composer);
    rmdir($temporary);
});

/**
 * A fake Composer whose `analyze` and `test:preflight` each wait for the other to have started, so a
 * sequential runner can never satisfy both: the proof of overlap is the rendezvous, not a timing.
 *
 * @return array{directory: string, composer: string, log: string}
 */
function preflightRendezvousComposer(): array
{
    $directory = sys_get_temp_dir() . '/capell-preflight-overlap-' . bin2hex(random_bytes(6));
    mkdir($directory, recursive: true);
    $composer = $directory . '/composer';
    file_put_contents($composer, <<<'BASH'
#!/usr/bin/env bash
echo "$1" >> "$CAPELL_PREFLIGHT_TEST_LOG"
case "$1" in
    analyze) mine=analyze; other=test:preflight ;;
    test:preflight) mine=test:preflight; other=analyze ;;
    *) exit 0 ;;
esac
touch "$CAPELL_PREFLIGHT_TEST_DIR/started.$mine"
if [ "${CAPELL_PREFLIGHT_TEST_RENDEZVOUS:-0}" = 1 ]; then
    for _ in $(seq 1 300); do
        [ -e "$CAPELL_PREFLIGHT_TEST_DIR/started.$other" ] && exit 0
        sleep 0.1
    done
    echo "$mine never saw $other start" >&2
    exit 9
fi
BASH);
    chmod($composer, 0755);

    return ['directory' => $directory, 'composer' => $composer, 'log' => $directory . '/calls.log'];
}

function runPreflightRendezvous(array $fixture, array $arguments, array $environment = []): Process
{
    $process = new Process(
        [PHP_BINARY, dirname(__DIR__, 2) . '/scripts/run-preflight.php', ...$arguments],
        dirname(__DIR__, 2),
        $environment + [
            'COMPOSER_BINARY' => $fixture['composer'],
            'CAPELL_PREFLIGHT_TEST_LOG' => $fixture['log'],
            'CAPELL_PREFLIGHT_TEST_DIR' => $fixture['directory'],
        ],
    );
    // Hang ceiling only: a busy host must slow this down, never fail it.
    $process->setTimeout(300);
    $process->run();

    return $process;
}

it('runs the quick preflight tests beside the static analysis and reports every stage in order', function (): void {
    $fixture = preflightRendezvousComposer();

    try {
        $process = runPreflightRendezvous($fixture, ['phpstan', 'tests'], ['CAPELL_PREFLIGHT_TEST_RENDEZVOUS' => '1']);

        expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput())
            ->and(file($fixture['log'], FILE_IGNORE_NEW_LINES))->toEqualCanonicalizing(['analyze', 'test:preflight'])
            ->and($process->getOutput())->toContain('PASS phpstan')->toContain('PASS tests')
            ->and(strpos($process->getOutput(), 'PASS phpstan'))->toBeLessThan(strpos($process->getOutput(), 'PASS tests'));
    } finally {
        array_map(unlink(...), glob($fixture['directory'] . '/{*,.[!.]*}', GLOB_BRACE) ?: []);
        rmdir($fixture['directory']);
    }
});

it('keeps the preflight stages one after another when asked or when it would rewrite files', function (array $arguments, array $environment): void {
    $fixture = preflightRendezvousComposer();

    try {
        $process = runPreflightRendezvous($fixture, $arguments, $environment);

        expect($process->getExitCode())->toBe(0, $process->getOutput() . $process->getErrorOutput())
            ->and(file($fixture['log'], FILE_IGNORE_NEW_LINES))->toBe(['analyze', 'test:preflight']);
    } finally {
        array_map(unlink(...), glob($fixture['directory'] . '/{*,.[!.]*}', GLOB_BRACE) ?: []);
        rmdir($fixture['directory']);
    }
})->with([
    'serial switch' => [['phpstan', 'tests'], ['CAPELL_PREFLIGHT_SERIAL' => '1']],
    'full preflight' => [['--all', 'phpstan', 'tests'], []],
]);

it('can stop after the first failed preflight gate for focused iteration', function (): void {
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
        'COMPOSER_BINARY=%s CAPELL_PREFLIGHT_TEST_LOG=%s %s %s --fail-fast phpstan tests 2>&1',
        escapeshellarg($composer),
        escapeshellarg($log),
        escapeshellarg(PHP_BINARY),
        escapeshellarg($root . '/scripts/run-preflight.php'),
    );

    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(1)
        ->and(file($log, FILE_IGNORE_NEW_LINES))->toBe(['analyze'])
        ->and(implode("\n", $output))
        ->toContain('FAIL phpstan')
        ->toContain('Preflight stopped after failed stage: phpstan')
        ->not->toContain('PASS tests');

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
