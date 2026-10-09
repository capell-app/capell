<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\CallArtisanCommandAction;
use Capell\Core\Support\Composer\ComposerProcessEnvironment;
use Capell\Core\Support\Process\ArtisanProcessEnvironment;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Capell\Core\Tests\Support\Install\RecordingConsoleKernel;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config([RuntimeBinaryResolver::PHP_CONFIG_KEY => PHP_BINARY]);
});

afterEach(function (): void {
    RecordingConsoleKernel::release();
});

function fakeInstallArtisanProcess(int $exitCode, string $output = '', string $errorOutput = ''): Process
{
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(120)->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(
        function (?callable $callback = null) use ($exitCode, $output, $errorOutput): int {
            if ($callback !== null) {
                $callback(Process::OUT, $output);
                $callback(Process::ERR, $errorOutput);
            }

            return $exitCode;
        },
    );
    $process->shouldReceive('isSuccessful')->andReturn($exitCode === 0);
    $process->shouldReceive('getExitCode')->andReturn($exitCode);
    $process->shouldReceive('getOutput')->andReturn($output);
    $process->shouldReceive('getErrorOutput')->andReturn($errorOutput);

    return $process;
}

function installArtisanCommandList(string $command): string
{
    return json_encode([
        'commands' => [['name' => $command, 'hidden' => true]],
        'namespaces' => [['id' => 'test', 'commands' => [$command, 'test:alias']]],
    ], JSON_THROW_ON_ERROR);
}

it('runs a registered command in process and captures its arguments output and exit code', function (): void {
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->never();
    app()->instance(ProcessFactoryInterface::class, $factory);

    Artisan::command('test:registered-install {name} {--count=} {--force}', function (): int {
        expect($this->argument('name'))->toBe('A site with spaces')
            ->and($this->option('count'))->toBe(7)
            ->and($this->option('force'))->toBeTrue();
        $this->line('Created the site.');

        return 17;
    });

    $result = CallArtisanCommandAction::run('test:registered-install', [
        'name' => 'A site with spaces', '--count' => 7, '--force' => true,
    ]);

    expect($result->exitCode)->toBe(17)
        ->and($result->combinedOutput())->toBe('Created the site.')
        ->and($result->errorOutput)->toBe('');
});

it('falls back to the project artisan and configured PHP with equivalent CLI arguments', function (): void {
    $kernel = RecordingConsoleKernel::bind();

    $probe = fakeInstallArtisanProcess(0, installArtisanCommandList('test:late-install'));
    $commandProcess = fakeInstallArtisanProcess(23, "Created the site.\n", "Validation failed.\n");
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->ordered()->withArgs(
        fn (array $command, string $cwd, ?array $environment): bool => $command === [
            PHP_BINARY, base_path('artisan'), 'list', '--format=json', '--no-interaction',
        ] && $cwd === base_path() && $environment === ArtisanProcessEnvironment::prepare(ComposerProcessEnvironment::forInstall($_SERVER)),
    )->andReturn($probe);
    $factory->shouldReceive('make')->once()->ordered()->withArgs(
        fn (array $command, string $cwd, ?array $environment): bool => $command === [
            PHP_BINARY, base_path('artisan'), 'test:late-install',
            '--count=7', '--force', '--languages=en', '--languages=fr', '--no-interaction',
            '--', 'A site with spaces',
        ] && $cwd === base_path() && $environment === ArtisanProcessEnvironment::prepare(ComposerProcessEnvironment::forInstall($_SERVER)),
    )->andReturn($commandProcess);
    app()->instance(ProcessFactoryInterface::class, $factory);

    $streamed = [];
    $arguments = [
        'name' => 'A site with spaces', '--count' => 7, '--force' => true,
        '--languages' => ['en', 'fr'], '--sites' => null, '--ansi' => false,
    ];
    $result = CallArtisanCommandAction::run('test:late-install', $arguments, onOutput: function (string $type, string $buffer) use (&$streamed): void {
        $streamed[] = [$type, $buffer];
    });

    expect($result->exitCode)->toBe(23)
        ->and($result->output)->toBe("Created the site.\n")
        ->and($result->errorOutput)->toBe("Validation failed.\n")
        ->and($result->combinedOutput())->toBe("Created the site.\nValidation failed.")
        ->and($streamed)->toBe([
            [Process::OUT, "Created the site.\n"], [Process::ERR, "Validation failed.\n"],
        ]);

    expect($kernel->allCalls)->toBe(1)
        ->and($kernel->calls)->toBe([]);
});

it('reports a translated error when neither application registers the command', function (): void {
    $kernel = RecordingConsoleKernel::bind();
    resolve(Translator::class)->addLines([
        'install.command.not_found' => 'Missing install command: :command',
    ], 'en', 'capell-core');

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->withArgs(
        fn (array $command): bool => $command[2] === 'list',
    )->andReturn(fakeInstallArtisanProcess(0, installArtisanCommandList('test:another-command')));
    app()->instance(ProcessFactoryInterface::class, $factory);

    expect(fn (): mixed => CallArtisanCommandAction::run('test:missing-install'))
        ->toThrow(RuntimeException::class, 'Missing install command: test:missing-install');

    expect($kernel->allCalls)->toBe(1)
        ->and($kernel->calls)->toBe([]);
});

it('preserves signature order and dash-prefixed values for named positional arguments', function (): void {
    $kernel = RecordingConsoleKernel::bind();
    $catalogue = json_encode(['commands' => [[
        'name' => 'test:positional-install',
        'definition' => ['arguments' => ['first' => [], 'second' => []]],
    ]]], JSON_THROW_ON_ERROR);
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->ordered()
        ->andReturn(fakeInstallArtisanProcess(0, $catalogue));
    $factory->shouldReceive('make')->once()->ordered()->withArgs(
        fn (array $arguments): bool => $arguments === [
            PHP_BINARY, base_path('artisan'), 'test:positional-install',
            '--no-interaction', '--', '--a-value', 'second value',
        ],
    )->andReturn(fakeInstallArtisanProcess(0));
    app()->instance(ProcessFactoryInterface::class, $factory);

    expect(CallArtisanCommandAction::run('test:positional-install', [
        'second' => 'second value', 'first' => '--a-value',
    ])->exitCode)->toBe(0);

    expect($kernel->allCalls)->toBe(1)
        ->and($kernel->calls)->toBe([]);
});

it('recognises hidden commands and aliases in the fresh application', function (string $command): void {
    $kernel = RecordingConsoleKernel::bind();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->ordered()
        ->andReturn(fakeInstallArtisanProcess(0, installArtisanCommandList('test:hidden-install')));
    $factory->shouldReceive('make')->once()->ordered()->withArgs(
        fn (array $arguments): bool => $arguments[2] === $command,
    )->andReturn(fakeInstallArtisanProcess(0, 'done'));
    app()->instance(ProcessFactoryInterface::class, $factory);

    expect(CallArtisanCommandAction::run($command)->combinedOutput())->toBe('done');

    expect($kernel->allCalls)->toBe(1)
        ->and($kernel->calls)->toBe([]);
})->with(['hidden command' => 'test:hidden-install', 'alias' => 'test:alias']);

it('preserves real boot failures when the fresh command probe fails or is malformed', function (int $probeExitCode, string $probeOutput): void {
    $kernel = RecordingConsoleKernel::bind();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->ordered()
        ->andReturn(fakeInstallArtisanProcess($probeExitCode, $probeOutput));
    $factory->shouldReceive('make')->once()->ordered()
        ->andReturn(fakeInstallArtisanProcess(19, '', 'Application boot failed.'));
    app()->instance(ProcessFactoryInterface::class, $factory);

    $result = CallArtisanCommandAction::run('test:late-install');

    expect($result->exitCode)->toBe(19)
        ->and($result->combinedOutput())->toBe('Application boot failed.')
        ->and($kernel->allCalls)->toBe(1)
        ->and($kernel->calls)->toBe([]);
})->with([
    'failed probe' => [1, 'Boot failed.'],
    'malformed JSON' => [0, 'Unexpected bootstrap output.'],
    'invalid command contract' => [0, '{"commands":[{}]}'],
]);

it('does not retry failures raised inside a registered command', function (Throwable $failure): void {
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->never();
    app()->instance(ProcessFactoryInterface::class, $factory);
    Artisan::command('test:registered-failure', function () use ($failure): never {
        throw $failure;
    });

    expect(fn (): mixed => CallArtisanCommandAction::run('test:registered-failure'))
        ->toThrow($failure::class, $failure->getMessage());
})->with([
    'ordinary failure' => [new RuntimeException('A real install failure.')],
    'missing nested command' => [new CommandNotFoundException('A nested command is missing.')],
]);

it('honours a caller requiring a fresh boot even when the command is registered', function (): void {
    Artisan::command('test:fresh-install', function (): never {
        throw new RuntimeException('The stale application must not run this command.');
    });
    expect(Artisan::all())->toHaveKey('test:fresh-install');
    $kernel = RecordingConsoleKernel::bind(['test:fresh-install' => []]);
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->ordered()
        ->andReturn(fakeInstallArtisanProcess(0, installArtisanCommandList('test:fresh-install')));
    $factory->shouldReceive('make')->once()->ordered()
        ->andReturn(fakeInstallArtisanProcess(0, 'fresh boot'));
    app()->instance(ProcessFactoryInterface::class, $factory);

    expect(CallArtisanCommandAction::run('test:fresh-install', freshProcess: true)->combinedOutput())
        ->toBe('fresh boot');
    expect($kernel->allCalls)->toBe(0)
        ->and($kernel->calls)->toBe([]);
});

it('streams fresh process output without retaining a second copy when capture is disabled', function (): void {
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(120)->andReturnSelf();
    $process->shouldReceive('disableOutput')->once()->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(function (callable $callback): int {
        $callback(Process::OUT, 'Demo progress.');

        return 0;
    });
    $process->shouldReceive('getExitCode')->once()->andReturn(0);
    $process->shouldReceive('getOutput')->never();
    $process->shouldReceive('getErrorOutput')->never();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->ordered()
        ->andReturn(fakeInstallArtisanProcess(0, installArtisanCommandList('test:streaming-demo')));
    $factory->shouldReceive('make')->once()->ordered()->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);

    $streamed = [];
    $result = CallArtisanCommandAction::run(
        'test:streaming-demo',
        freshProcess: true,
        captureOutput: false,
        onOutput: function (string $type, string $buffer) use (&$streamed): void {
            $streamed[] = $buffer;
        },
    );

    expect($streamed)->toBe(['Demo progress.'])
        ->and($result->exitCode)->toBe(0)
        ->and($result->combinedOutput())->toBe('');
});

it('captures the registered command output even when it dispatches another artisan command', function (): void {
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->never();
    app()->instance(ProcessFactoryInterface::class, $factory);
    Artisan::command('test:nested-install-child', function (): int {
        $this->line('Child output.');

        return 0;
    });
    Artisan::command('test:nested-install-parent', function (): int {
        Artisan::call('test:nested-install-child');
        $this->line('Parent output.');

        return 0;
    });

    expect(CallArtisanCommandAction::run('test:nested-install-parent')->combinedOutput())->toBe('Parent output.');
});
