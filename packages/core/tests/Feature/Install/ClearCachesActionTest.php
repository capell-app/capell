<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\ClearCachesAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallCacheOptionCatalog;
use Capell\Core\Support\Install\NullProgressReporter;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

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
    $reporter = new RecordingClearCachesProgressReporter;

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn([]);
    $kernel->shouldReceive('call')->with('optimize:clear')->never();
    $kernel->shouldReceive('call')->never();
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['all'], $reporter);

    expect($reporter->reports)
        ->toContain('Skipped optimize:clear; Testbench package manifests are shared across parallel tests')
        ->toContain('Skipped capell:html-cache:clear; command is not available')
        ->toContain('Skipped capell:package-cache:clear; command is not available');
});

it('calls config:clear when config is selected', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('config:clear')->once()->andReturn(0);
    $kernel->shouldReceive('call')->zeroOrMoreTimes()->andReturn(0);
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['config'], new NullProgressReporter);
});

it('reports progress and clears extension caches around selected cache commands', function (): void {
    $reporter = new RecordingClearCachesProgressReporter;

    CapellCore::shouldReceive('clearExtensionCache')->twice();

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('config:clear')->once()->andReturn(0);
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['config'], $reporter);

    expect($reporter->steps)
        ->toBe(['Clearing caches…'])
        ->and($reporter->reports)
        ->toBe(['✓ Config cache cleared']);
});

it('calls the html cache clear command when page is selected and available', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->once()->andReturn(['capell:html-cache:clear' => true]);
    $kernel->shouldReceive('call')->with('capell:html-cache:clear')->once()->andReturn(0);
    $kernel->shouldReceive('call')->zeroOrMoreTimes()->andReturn(0);
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['page'], new NullProgressReporter);
});

it('calls view:clear when views is selected', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('view:clear')->once()->andReturn(0);
    $kernel->shouldReceive('call')->zeroOrMoreTimes()->andReturn(0);
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['views'], new NullProgressReporter);
});

it('calls optional capell and filament cache commands when they are selected and available', function (): void {
    $availableCommands = [
        'capell:admin-clear-cache' => true,
        'capell:clear-components-cache' => true,
        'capell:admin-clear-widgets-cache' => true,
        'capell:admin-clear-configurators-cache' => true,
        'filament:clear-cached-components' => true,
        'capell:package-cache:clear' => true,
    ];

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->zeroOrMoreTimes()->andReturn($availableCommands);
    $kernel->shouldReceive('call')->with('capell:admin-clear-cache')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('capell:clear-components-cache')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('capell:admin-clear-widgets-cache')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('capell:admin-clear-configurators-cache')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('filament:clear-cached-components')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('capell:package-cache:clear')->once()->andReturn(0);
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run([
        'admin',
        'components',
        'widgets',
        'configurators',
        'filament-components',
        'packages',
    ], new NullProgressReporter);
});

it('executes a cache command for every individually advertised cache key', function (): void {
    $availableCommands = array_fill_keys(
        array_column(InstallCacheOptionCatalog::optionalOptions(), 'command'),
        true,
    );
    $availableCommands['capell:html-cache:clear'] = true;
    $executedCommands = [];

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->zeroOrMoreTimes()->andReturn($availableCommands);
    $kernel->shouldReceive('call')->zeroOrMoreTimes()->andReturnUsing(
        function (string $command) use (&$executedCommands): int {
            $executedCommands[] = $command;

            return 0;
        },
    );
    $this->app->instance(ConsoleKernel::class, $kernel);

    $advertisedCacheKeys = array_values(array_filter(
        array_keys([
            ...InstallCacheOptionCatalog::baseOptions(),
            ...InstallCacheOptionCatalog::optionalOptions(),
        ]),
        static fn (string $cacheKey): bool => $cacheKey !== 'all',
    ));

    ClearCachesAction::run($advertisedCacheKeys, new NullProgressReporter);

    expect($executedCommands)->toBe([
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

it('clears generated capell package cache files when all is selected', function (): void {
    $availableCommands = [
        'capell:html-cache:clear' => true,
        'capell:package-cache:clear' => true,
    ];

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn($availableCommands);
    $kernel->shouldReceive('call')->with('optimize:clear')->never();
    $kernel->shouldReceive('call')->with('capell:html-cache:clear')->once()->andReturn(0);
    $kernel->shouldReceive('call')->with('capell:package-cache:clear')->once()->andReturn(0);
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['all'], new NullProgressReporter);
});

it('runs optimize:clear outside testbench and reports success', function (): void {
    $originalBootstrapPath = $this->app->bootstrapPath();
    $this->app->useBootstrapPath(__DIR__);

    $reporter = new RecordingClearCachesProgressReporter;

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn([]);
    $kernel->shouldReceive('call')->with('optimize:clear')->once()->andReturn(0);
    $this->app->instance(ConsoleKernel::class, $kernel);

    try {
        ClearCachesAction::run(['all'], $reporter);
    } finally {
        $this->app->useBootstrapPath($originalBootstrapPath);
    }

    expect($reporter->reports)
        ->toContain('✓ All caches cleared');
});

it('reports optimize:clear exceptions outside testbench', function (): void {
    $originalBootstrapPath = $this->app->bootstrapPath();
    $this->app->useBootstrapPath(__DIR__);

    $reporter = new RecordingClearCachesProgressReporter;

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn([]);
    $kernel->shouldReceive('call')->with('optimize:clear')->once()->andThrow(new RuntimeException('manifest cache is locked'));
    $this->app->instance(ConsoleKernel::class, $kernel);

    try {
        ClearCachesAction::run(['all'], $reporter);
    } finally {
        $this->app->useBootstrapPath($originalBootstrapPath);
    }

    expect($reporter->reports)
        ->toContain('Skipped optimize:clear; manifest cache is locked');
});

it('reports cache commands that return a failing exit code', function (): void {
    $reporter = new RecordingClearCachesProgressReporter;

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('config:clear')->once()->andReturn(1);
    $kernel->shouldReceive('output')->once()->andReturn('Unable to delete bootstrap/cache/config.php');
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['config'], $reporter);

    expect($reporter->reports)
        ->toContain('Unable to clear config:clear; Unable to delete bootstrap/cache/config.php');
});

it('reports failing cache command status when command output is blank', function (): void {
    $reporter = new RecordingClearCachesProgressReporter;

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('config:clear')->once()->andReturn(12);
    $kernel->shouldReceive('output')->once()->andReturn("  \n\t");
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['config'], $reporter);

    expect($reporter->reports)
        ->toContain('Unable to clear config:clear; command exited with status 12');
});

it('reports cache commands that throw exceptions', function (): void {
    $reporter = new RecordingClearCachesProgressReporter;

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->with('config:clear')->once()->andThrow(new RuntimeException('cache store is offline'));
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['config'], $reporter);

    expect($reporter->reports)
        ->toContain('Unable to clear config:clear; cache store is offline');
});

it('skips optional cache commands that are unavailable', function (): void {
    $reporter = new RecordingClearCachesProgressReporter;

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn([]);
    $kernel->shouldReceive('call')->never();
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run(['page', 'filament-components'], $reporter);

    expect($reporter->reports)
        ->toContain('Skipped capell:html-cache:clear; command is not available')
        ->toContain('Skipped filament:clear-cached-components; command is not available');
});

it('skips all cache commands when cachesToClear is empty', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('call')->never();
    $this->app->instance(ConsoleKernel::class, $kernel);

    ClearCachesAction::run([], new NullProgressReporter);
});
