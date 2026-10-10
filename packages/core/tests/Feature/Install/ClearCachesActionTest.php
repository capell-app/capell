<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallCacheOptionCatalog;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Process\RuntimeBinaryResolver;
use Capell\Tests\Support\Fakes\FakeConsoleKernel;
use Capell\Tests\Support\Fakes\FakeProcessFactory;
use Symfony\Component\Console\Exception\CommandNotFoundException;

beforeEach(function (): void {
    $this->processes = FakeProcessFactory::bind();
    $this->reporter = new RecordingClearCachesProgressReporter;
});

/**
 * optimize:clear only runs when the bootstrap path is not Testbench's shared
 * skeleton, so point it at this directory for the duration of the callback.
 */
function outsideTestbench(Closure $callback): void
{
    $originalBootstrapPath = app()->bootstrapPath();
    app()->useBootstrapPath(__DIR__);

    try {
        $callback();
    } finally {
        app()->useBootstrapPath($originalBootstrapPath);
    }
}

function expectFreshOptimizeClearProcess(FakeProcessFactory $processes): void
{
    expect($processes->commands())->toBe([[...new RuntimeBinaryResolver()->php(), 'artisan', 'optimize:clear', '--no-interaction']])
        ->and($processes->processes[0]->getWorkingDirectory())->toBe(base_path())
        ->and($processes->processes[0]->getTimeout())->toEqual(120);
}

final class RecordingClearCachesProgressReporter implements ProgressReporter
{
    /** @var list<string> */
    public array $steps = [];

    /** @var list<string> */
    public array $reports = [];

    /** @var list<string> */
    public array $errors = [];

    public function step(string $label): void
    {
        $this->steps[] = $label;
    }

    public function report(string $line): void
    {
        $this->reports[] = $line;
    }

    public function error(string $line): void
    {
        $this->errors[] = $line;
    }
}

it('skips optimize:clear in testbench when all is selected', function (): void {
    $kernel = FakeConsoleKernel::bind();

    ClearCachesAction::run(['all'], $this->reporter);

    expect($kernel->calls)->toBe([])
        ->and($kernel->availabilityChecks)->toBe(2)
        ->and($this->reporter->reports)
        ->toContain('Skipped optimize:clear; Testbench package manifests are shared across parallel tests')
        ->toContain('Skipped capell:html-cache:clear; command is not available')
        ->toContain('Skipped capell:package-cache; command is not available');
    $this->processes->assertNothingRan();
});

it('runs the command behind each selected cache key', function (array $cacheKeys, array $available, array $expectedCalls): void {
    $kernel = FakeConsoleKernel::bind($available);

    ClearCachesAction::run($cacheKeys, new NullProgressReporter);

    expect($kernel->calls)->toBe($expectedCalls);
    $this->processes->assertNothingRan();
})->with([
    'config' => [['config'], [], ['config:clear']],
    'views' => [['views'], [], ['view:clear']],
    'page when available' => [['page'], ['capell:html-cache:clear' => true], ['capell:html-cache:clear']],
    'optional capell and filament commands when available' => [
        ['admin', 'components', 'widgets', 'configurators', 'filament-components', 'packages'],
        [
            'capell:admin-clear-cache' => true,
            'capell:clear-components-cache' => true,
            'capell:admin-clear-widgets-cache' => true,
            'capell:admin-clear-configurators-cache' => true,
            'filament:clear-cached-components' => true,
            'capell:package-cache' => true,
        ],
        [
            'capell:admin-clear-cache',
            'capell:clear-components-cache',
            'capell:admin-clear-widgets-cache',
            'capell:admin-clear-configurators-cache',
            'filament:clear-cached-components',
            'capell:package-cache',
        ],
    ],
    'all rebuilds generated capell package cache files' => [
        ['all'],
        ['capell:html-cache:clear' => true, 'capell:package-cache' => true],
        ['capell:html-cache:clear', 'capell:package-cache'],
    ],
    'nothing selected' => [[], [], []],
]);

it('reports progress and clears extension caches around selected cache commands', function (): void {
    CapellCore::shouldReceive('clearExtensionCache')->twice();
    $kernel = FakeConsoleKernel::bind();

    ClearCachesAction::run(['config'], $this->reporter);

    expect($kernel->calls)->toBe(['config:clear'])
        ->and($this->reporter->steps)->toBe(['Clearing caches…'])
        ->and($this->reporter->reports)->toBe(['✓ Config cache cleared']);
});

it('executes a cache command for every individually advertised cache key', function (): void {
    $availableCommands = array_fill_keys(
        array_column(InstallCacheOptionCatalog::optionalOptions(), 'command'),
        true,
    );
    $availableCommands['capell:html-cache:clear'] = true;
    $kernel = FakeConsoleKernel::bind($availableCommands);

    $advertisedCacheKeys = array_values(array_filter(
        array_keys([
            ...InstallCacheOptionCatalog::baseOptions(),
            ...InstallCacheOptionCatalog::optionalOptions(),
        ]),
        static fn (string $cacheKey): bool => $cacheKey !== 'all',
    ));

    ClearCachesAction::run($advertisedCacheKeys, new NullProgressReporter);

    expect($kernel->calls)->toBe([
        'capell:html-cache:clear',
        'config:clear',
        'view:clear',
        'capell:admin-clear-cache',
        'capell:clear-components-cache',
        'capell:admin-clear-widgets-cache',
        'capell:admin-clear-configurators-cache',
        'filament:clear-cached-components',
    ]);
});

it('runs optimize:clear outside testbench and reports success', function (): void {
    $kernel = FakeConsoleKernel::bind();

    outsideTestbench(function (): void {
        ClearCachesAction::run(['all'], $this->reporter);
    });

    expect($kernel->calls)->toBe(['optimize:clear'])
        ->and($kernel->availabilityChecks)->toBe(2)
        ->and($this->reporter->reports)->toContain('✓ All caches cleared');
    $this->processes->assertNothingRan();
});

it('retries optimize:clear in a fresh process when a hook command is undefined', function (): void {
    $kernel = FakeConsoleKernel::bind()
        ->returns('optimize:clear', new CommandNotFoundException('Command "filament:clear-cached-components" is not defined.'));
    $this->processes->push(output: 'All caches cleared.');

    outsideTestbench(function (): void {
        ClearCachesAction::run(['all'], $this->reporter);
    });

    expectFreshOptimizeClearProcess($this->processes);
    expect($kernel->calls)->toBe(['optimize:clear'])
        ->and($kernel->availabilityChecks)->toBe(2)
        ->and($this->reporter->reports)
        ->toContain('→ optimize:clear ran in a fresh process')
        ->toContain('✓ All caches cleared');
});

it('reports a failed fresh optimize:clear retry without claiming success', function (int $exitCode, string $output, string $errorOutput, ?Throwable $throws, string $message): void {
    $kernel = FakeConsoleKernel::bind()->returns('optimize:clear', new CommandNotFoundException('Missing hook command'));
    $this->processes->push(exitCode: $exitCode, output: $output, errorOutput: $errorOutput, throws: $throws);

    outsideTestbench(function () use ($message): void {
        expect(fn (): mixed => ClearCachesAction::run(['all'], $this->reporter))
            ->toThrow(RuntimeException::class, $message);
    });

    expectFreshOptimizeClearProcess($this->processes);
    expect($kernel->calls)->toBe(['optimize:clear'])
        ->and($kernel->availabilityChecks)->toBe(0)
        ->and($this->reporter->reports)->not->toContain('✓ All caches cleared');
})->with([
    'output and error output' => [1, 'Clearing compiled views.', 'Fresh process cache store is offline.', null, "Unable to clear optimize:clear; Clearing compiled views.\nFresh process cache store is offline."],
    'blank output' => [12, " \n\t", '', null, 'Unable to clear optimize:clear; command exited with status 12'],
    'process exception' => [1, 'Clearing compiled views.', '', new RuntimeException('Process timed out'), "Unable to clear optimize:clear; Clearing compiled views.\nProcess timed out"],
]);

it('reports a failing optimize:clear outside testbench without a fresh-process retry', function (int|Throwable $result, string $output, string $message): void {
    $kernel = FakeConsoleKernel::bind()->returns('optimize:clear', $result, $output);

    outsideTestbench(function () use ($message): void {
        expect(fn (): mixed => ClearCachesAction::run(['all'], $this->reporter))
            ->toThrow(RuntimeException::class, $message);
    });

    expect($kernel->calls)->toBe(['optimize:clear'])
        ->and($kernel->availabilityChecks)->toBe(0)
        ->and($this->reporter->reports)->toContain($message)->not->toContain('✓ All caches cleared');
    $this->processes->assertNothingRan();
})->with([
    'exit code with output' => [1, 'Unable to delete bootstrap/cache/config.php', 'Unable to clear optimize:clear; Unable to delete bootstrap/cache/config.php'],
    'exit code with blank output' => [12, '', 'Unable to clear optimize:clear; command exited with status 12'],
    'exception' => [new RuntimeException('manifest cache is locked'), '', 'Unable to clear optimize:clear; manifest cache is locked'],
]);

it('reports and propagates a failing cache command', function (int|Throwable $result, string $output, string $message): void {
    $kernel = FakeConsoleKernel::bind()->returns('config:clear', $result, $output);

    expect(fn (): mixed => ClearCachesAction::run(['config'], $this->reporter))
        ->toThrow(RuntimeException::class, $message);

    expect($kernel->calls)->toBe(['config:clear'])
        ->and($this->reporter->reports)->toContain($message);
    $this->processes->assertNothingRan();
})->with([
    'exit code with output' => [1, 'Unable to delete bootstrap/cache/config.php', 'Unable to clear config:clear; Unable to delete bootstrap/cache/config.php'],
    'exit code with blank output' => [12, "  \n\t", 'Unable to clear config:clear; command exited with status 12'],
    'exception' => [new RuntimeException('cache store is offline'), '', 'Unable to clear config:clear; cache store is offline'],
    'undefined command is not retried in a fresh process' => [new CommandNotFoundException('Command "config:clear" is not defined.'), '', 'Unable to clear config:clear; Command "config:clear" is not defined.'],
]);

it('skips optional cache commands that are unavailable', function (): void {
    $kernel = FakeConsoleKernel::bind();

    ClearCachesAction::run(['page', 'filament-components'], $this->reporter);

    expect($kernel->calls)->toBe([])
        ->and($kernel->availabilityChecks)->toBe(2)
        ->and($this->reporter->reports)
        ->toContain('Skipped capell:html-cache:clear; command is not available')
        ->toContain('Skipped filament:clear-cached-components; command is not available');
});
