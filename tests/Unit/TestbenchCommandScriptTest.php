<?php

declare(strict_types=1);

use Capell\Tests\Support\ScriptFixture;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function testbenchCommandFixture(): ScriptFixture
{
    $fixture = new ScriptFixture;
    $fixture->copy('scripts/run-testbench-command.php');
    $fixture->write('scripts/configure-testbench-runtime-role.php', <<<'CHILD'
<?php
if (getenv('FIXTURE_BOOTSTRAP_FAIL') === 'true') {
    fwrite(STDERR, 'bootstrap fixture failed');
    exit(19);
}
file_put_contents(dirname(__DIR__) . '/bootstrap-ready', 'ready');
CHILD);
    $fixture->write('vendor/bin/testbench', <<<'CHILD'
<?php
file_put_contents(dirname(__DIR__, 2) . '/command-ran', 'ran');
echo json_encode([
    'arguments' => array_slice($argv, 1),
    'cache_store' => getenv('CACHE_STORE'),
    'cache_paths' => array_map(getenv(...), ['APP_CONFIG_CACHE', 'APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE']),
    'ready' => is_file(dirname(__DIR__, 2) . '/bootstrap-ready'),
], JSON_THROW_ON_ERROR);
fwrite(STDERR, 'child diagnostic');
exit((int) getenv('FIXTURE_COMMAND_EXIT'));
CHILD);

    return $fixture;
}

it('gives Composer cache commands the same portable cache isolation', function (string $script, string $operation): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $commands = array_filter((array) $composer['scripts'][$script], static fn (string $command): bool => str_contains($command, $operation));
    expect($commands)->not->toBeEmpty();
    $fixture = testbenchCommandFixture();
    $fixture->write('scripts/with-lock.php', <<<'CHILD'
<?php
require dirname(__DIR__) . '/vendor/autoload.php';
$process = new Symfony\Component\Process\Process(array_slice($argv, 3), dirname(__DIR__));
$exit = $process->run(static function (string $type, string $buffer): void {
    fwrite($type === 'err' ? STDERR : STDOUT, $buffer);
});
exit($exit);
CHILD);
    try {
        foreach ($commands as $command) {
            $process = Process::fromShellCommandline(str_replace('@php', escapeshellarg(PHP_BINARY), $command), $fixture->root, [
                'CACHE_STORE' => 'database',
                'APP_CONFIG_CACHE' => '/parent/config.php',
            ]);
            expect($process->run())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            expect($result['cache_store'])->toBe('array')
                ->and($result['cache_paths'])->toBe([false, false, false, false, false])
                ->and($result['ready'])->toBe($operation === 'optimize');
        }
    } finally {
        $fixture->close();
    }
})->with([
    'clearing' => ['clear', 'package:purge-skeleton'],
    'coverage' => ['coverage', 'optimize'],
    'coverage report' => ['coverage-report', 'optimize'],
]);

it('runs portable commands with an array cache and isolated cache paths', function (string $command, bool $ready): void {
    $fixture = testbenchCommandFixture();
    try {
        $process = $fixture->php('scripts/run-testbench-command.php', [$command, '--label=two words'], [
            'CACHE_STORE' => 'database',
            'APP_CONFIG_CACHE' => '/parent/config.php',
            'APP_PACKAGES_CACHE' => '/parent/packages.php',
            'APP_SERVICES_CACHE' => '/parent/services.php',
            'APP_ROUTES_CACHE' => '/parent/routes.php',
            'APP_EVENTS_CACHE' => '/parent/events.php',
        ]);
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result)->toBe([
            'arguments' => [$command, '--label=two words'],
            'cache_store' => 'array',
            'cache_paths' => [false, false, false, false, false],
            'ready' => $ready,
        ])->and($process->getErrorOutput())->toBe('child diagnostic');
    } finally {
        $fixture->close();
    }
})->with(['listing' => ['list', true], 'optimising' => ['optimize', true], 'purging' => ['package:purge-skeleton', false]]);

it('stops before Testbench when bootstrap preparation fails', function (): void {
    $fixture = testbenchCommandFixture();
    try {
        $process = $fixture->php('scripts/run-testbench-command.php', ['list'], ['FIXTURE_BOOTSTRAP_FAIL' => 'true']);
        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('bootstrap fixture failed', 'Exit code: 19')
            ->and($fixture->root . '/command-ran')->not->toBeFile();
    } finally {
        $fixture->close();
    }
});

it('reports a failed Testbench child with its exit code and diagnostic', function (): void {
    $fixture = testbenchCommandFixture();
    try {
        $process = $fixture->php('scripts/run-testbench-command.php', ['package:purge-skeleton'], ['FIXTURE_COMMAND_EXIT' => '23']);
        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('child diagnostic', 'Testbench command failed with exit code 23');
    } finally {
        $fixture->close();
    }
});

it('rejects an absent Testbench command without executing a child', function (): void {
    $fixture = testbenchCommandFixture();
    try {
        $process = $fixture->php('scripts/run-testbench-command.php');
        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('A Testbench command is required')
            ->and($fixture->root . '/command-ran')->not->toBeFile();
    } finally {
        $fixture->close();
    }
});

it('boots the portable Testbench command runner with the runtime role enabled', function (): void {
    $process = new Process([
        PHP_BINARY,
        'scripts/run-testbench-command.php',
        'list',
        '--format=json',
    ], dirname(__DIR__, 2), [
        'CAPELL_TESTBENCH_RUNTIME_ROLE' => 'true',
        'CACHE_STORE' => 'array',
    ]);
    $process->setTimeout(120);
    $process->mustRun();

    $registry = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $commandNames = array_column($registry['commands'] ?? [], 'name');

    expect($commandNames)->toContain('filament:install');
});

it('does not inherit the calling applications config cache when listing commands', function (): void {
    $directory = sys_get_temp_dir() . '/capell-command-parent-' . bin2hex(random_bytes(6));
    $files = new Filesystem;
    $files->dumpFile($directory . '/config.php', '<?php declare(strict_types=1); return [];');

    try {
        $process = new Process([
            PHP_BINARY,
            'scripts/run-testbench-command.php',
            'list',
            '--format=json',
        ], dirname(__DIR__, 2), [
            'APP_CONFIG_CACHE' => $directory . '/config.php',
            'CAPELL_TESTBENCH_RUNTIME_ROLE' => 'true',
        ]);
        $process->setTimeout(120);
        $process->mustRun();

        $registry = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect(array_column($registry['commands'], 'name'))->toContain('filament:install')
            ->and(file_get_contents($directory . '/config.php'))->toBe('<?php declare(strict_types=1); return [];');
    } finally {
        $files->remove($directory);
    }
});
