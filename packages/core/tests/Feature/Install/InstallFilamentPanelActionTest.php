<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Actions\Runtime\BuildRuntimeRoleProviderManifestsAction;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Tests\Support\Install\RecordingInstallProgressReporter;
use Filament\PanelProvider;
use Filament\PanelRegistry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function (): void {
    $this->originalBasePath = app()->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-filament-install-' . bin2hex(random_bytes(8));

    File::ensureDirectoryExists($this->temporaryBasePath);
    app()->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);
    File::deleteDirectory($this->temporaryBasePath);
});

function bindFilamentPanelInstallProcessFactory(bool $successful = true, string $output = 'Filament panel installed', string $errorOutput = '', bool $scaffoldPanel = true): void
{
    $process = Mockery::mock(SymfonyProcess::class);
    $process
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();
    $process
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (?callable $callback = null) use ($successful, $output, $scaffoldPanel): int {
            if ($successful && $scaffoldPanel) {
                File::ensureDirectoryExists(app_path('Providers/Filament'));
                File::put(app_path('Providers/Filament/FilamentInstallTestPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

class FilamentInstallTestPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->default();
    }
}
PHP);
            }

            if ($callback !== null && $output !== '') {
                $callback('out', $output);
            }

            return $successful ? 0 : 1;
        });
    $process->shouldReceive('isSuccessful')->andReturn($successful);
    $process->shouldReceive('getErrorOutput')->andReturn($errorOutput);
    $process->shouldReceive('getOutput')->andReturn($output);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory
        ->shouldReceive('make')
        ->once()
        ->with(
            Mockery::on(fn (array|string $command): bool => $command === [
                PHP_BINARY,
                'artisan',
                'filament:install',
                ...($scaffoldPanel ? ['--panels'] : []),
                '--no-interaction',
            ]),
            Mockery::type('string'),
            Mockery::type('array'),
        )
        ->andReturn($process);

    app()->instance(ProcessFactoryInterface::class, $factory);
}

it('does not run filament install when a themed panel provider already exists', function (): void {
    File::ensureDirectoryExists(app_path('Providers/Filament'));
    File::put(app_path('Providers/Filament/FilamentInstallTestPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

class FilamentInstallTestPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->viteTheme('resources/css/filament/admin/theme.css');
    }
}
PHP);

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldNotReceive('all');
    $kernel->shouldNotReceive('call');
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    $providerPath = app_path('Providers/Filament/FilamentInstallTestPanelProvider.php');
    $providerContents = File::get($providerPath);
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldNotReceive('make');

    app()->instance(ProcessFactoryInterface::class, $factory);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(File::get($providerPath))->toBe($providerContents);
});

it('configures the theme when an existing filament panel has no stylesheet', function (): void {
    File::ensureDirectoryExists(app_path('Providers/Filament'));
    File::put(app_path('Providers/Filament/FilamentInstallTestPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

class FilamentInstallTestPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin');
    }
}
PHP);

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldNotReceive('all');
    $kernel->shouldNotReceive('call');
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    $reporter = new RecordingInstallProgressReporter;

    InstallFilamentPanelAction::run($reporter);

    expect($reporter->lines)->toContain(
        '→ Filament admin panel already configured.',
        '→ Configured the compiled Filament admin theme.',
    );
});

it('runs filament install with panel scaffolding when no panel provider exists', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn(['filament:install' => true]);
    $kernel->shouldReceive('call')->once()->with('filament:install', [
        '--panels' => true,
        '--no-interaction' => true,
    ], Mockery::type(BufferedOutput::class))->andReturnUsing(function (): int {
        File::ensureDirectoryExists(app_path('Providers/Filament'));
        File::put(app_path('Providers/Filament/FilamentInstallTestPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

class FilamentInstallTestPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->default();
    }
}
PHP);

        return 0;
    });
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();
    resolve(PanelRegistry::class);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(resource_path('css/filament/admin/theme.css'))->toBeFile()
        ->and(File::get(resource_path('css/filament/admin/theme.css')))
        ->toContain("@import '../../../../vendor/filament/filament/resources/css/theme.css';")
        ->toContain("@source '../../../../app/Filament/**/*';")
        ->toContain("@source '../../../../resources/views/filament/**/*';")
        ->and(app()->getProvider('App\\Providers\\Filament\\FilamentInstallTestPanelProvider'))
        ->toBeInstanceOf(PanelProvider::class)
        ->and(resolve(PanelRegistry::class)->get('admin'))
        ->not->toBeNull()
        ->and(resolve(PanelRegistry::class)->getDefault()->getId())
        ->toBe('admin');
});

it('rejects a failed filament install even when it leaves a partial panel provider', function (): void {
    bindFilamentPanelInstallProcessFactory(false, '', 'Panel scaffolding failed.', scaffoldPanel: false);

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn(['filament:install' => true]);
    $kernel->shouldReceive('call')->once()->with('filament:install', [
        '--panels' => true,
        '--no-interaction' => true,
    ], Mockery::type(BufferedOutput::class))->andReturnUsing(function (string $command, array $arguments, BufferedOutput $output): int {
        $output->writeln('Panel scaffolding stopped before completion.');
        File::ensureDirectoryExists(app_path('Providers/Filament'));
        File::put(app_path('Providers/Filament/PartialPanelProvider.php'), '<?php');

        return 19;
    });
    $kernel->shouldReceive('output')->andReturn('Panel scaffolding stopped before completion.');
    app()->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();
    $reporter = new RecordingInstallProgressReporter;

    expect(fn (): mixed => InstallFilamentPanelAction::run($reporter))
        ->toThrow(RuntimeException::class, 'Failed to scaffold Filament panel: Panel scaffolding failed.');

    expect(implode("\n", $reporter->lines))
        ->toContain("Artisan command 'filament:install' failed with exit code 19.")
        ->and(file_exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('falls back to a fresh process when filament install is not registered in-process', function (): void {
    bindFilamentPanelInstallProcessFactory();

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->once()->andReturn([]);
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    $reporter = new RecordingInstallProgressReporter;

    InstallFilamentPanelAction::run($reporter);

    expect(file_exists(app_path('Providers/Filament/FilamentInstallTestPanelProvider.php')))->toBeTrue()
        ->and($reporter->lines)->toContain('Filament panel installed');
});

it('falls back to a fresh process when in-process filament install fails', function (): void {
    bindFilamentPanelInstallProcessFactory();

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn(['filament:install' => true]);
    $kernel->shouldReceive('call')->once()->andThrow(new RuntimeException('Filament command failed.'));
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(file_exists(app_path('Providers/Filament/FilamentInstallTestPanelProvider.php')))->toBeTrue();
});

it('preserves the default panel and login when scaffolding succeeds before asset publication fails', function (): void {
    $providerPath = app_path('Providers/Filament/FilamentRecoveryTestPanelProvider.php');
    $providerContents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

class FilamentRecoveryTestPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->default()->login();
    }
}
PHP;

    app()->instance(PanelRegistry::class, new PanelRegistry);

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn(['filament:install' => true]);
    $kernel->shouldReceive('call')->once()->with('filament:install', [
        '--panels' => true,
        '--no-interaction' => true,
    ], Mockery::type(BufferedOutput::class))->andReturnUsing(function () use ($providerPath, $providerContents): never {
        File::ensureDirectoryExists(dirname($providerPath));
        File::put($providerPath, $providerContents);
        InstallFilamentPanelAction::registerPanelProviders();

        throw new RuntimeException('Asset publication failed after panel scaffolding.');
    });
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    app()->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    $recoveryCommand = [];
    $process = Mockery::mock(SymfonyProcess::class);
    $process->shouldReceive('setTimeout')->once()->with(300)->andReturnSelf();
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->with(
        Mockery::type('array'),
        $this->temporaryBasePath,
        Mockery::type('array'),
    )->andReturnUsing(function (array $command, string $workingDirectory, array $environment) use (&$recoveryCommand, $process, $providerPath, $providerContents): SymfonyProcess {
        $recoveryCommand = $command;
        $process->shouldReceive('run')->once()->andReturnUsing(function (?callable $callback = null) use ($command, $providerPath, $providerContents): int {
            if (in_array('--panels', $command, true)) {
                File::put($providerPath, str_replace(['->default()', '->login()'], '', $providerContents));
            }

            if ($callback !== null) {
                $callback('out', 'Filament assets installed');
            }

            return 0;
        });

        return $process;
    });
    app()->instance(ProcessFactoryInterface::class, $factory);

    $reporter = new RecordingInstallProgressReporter;
    InstallFilamentPanelAction::run($reporter);

    expect(File::get($providerPath))->toBe(str_replace('->login()', "->login()->viteTheme('resources/css/filament/admin/theme.css')", $providerContents))
        ->and($recoveryCommand)->toBe([PHP_BINARY, 'artisan', 'filament:install', '--no-interaction'])
        ->and(resolve(PanelRegistry::class)->getDefault()->getId())->toBe('admin')
        ->and(resolve(PanelRegistry::class)->getDefault()->hasLogin())->toBeTrue()
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile()
        ->and($reporter->lines)->toContain('Filament assets installed');
});

it('throws when the fresh filament process fails', function (): void {
    bindFilamentPanelInstallProcessFactory(false, '', 'Composer could not load Filament.');

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->once()->andReturn([]);
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    InstallFilamentPanelAction::run(new NullProgressReporter);
})->throws(RuntimeException::class, 'Failed to scaffold Filament panel: Composer could not load Filament.');

it('refreshes existing runtime role manifests before a subsequent admin subprocess on retry', function (): void {
    File::ensureDirectoryExists(app_path('Providers/Filament'));
    File::put(app_path('Providers/Filament/FilamentInstallRetryPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

class FilamentInstallRetryPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->default();
    }
}
PHP);
    $originalApplication = app();
    $application = new Application($this->temporaryBasePath);
    Container::setInstance($originalApplication);
    $paths = new RuntimeRoleCachePaths($application);
    app()->instance(RuntimeRoleCachePaths::class, $paths);
    File::ensureDirectoryExists(dirname($paths->metadata()));
    File::put($paths->metadata(), '<?php return [];');
    File::put($application->bootstrapPath('cache/packages.php'), '<?php return [];');
    $provider = 'App\\Providers\\Filament\\FilamentInstallRetryPanelProvider';
    File::put($application->bootstrapPath('providers.php'), '<?php return ' . var_export([$provider], true) . ';');
    require_once app_path('Providers/Filament/FilamentInstallRetryPanelProvider.php');
    app()->instance(BuildRuntimeRoleProviderManifestsAction::class, new BuildRuntimeRoleProviderManifestsAction(
        $application,
        new Filesystem,
        $paths,
        new RuntimeRoleProviderPolicy,
    ));

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(require $paths->providers(RuntimeRole::Combined))->toContain($provider)
        ->and(require $paths->providers(RuntimeRole::Public))->not->toContain($provider);
});

it('does not rebuild a panel whose provider the application already registered', function (): void {
    $providerPath = app_path('Providers/Filament/BootRegisteredTestPanelProvider.php');
    File::ensureDirectoryExists(dirname($providerPath));
    File::put($providerPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

class BootRegisteredTestPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        // Each build replays plugin registration, and with it every panel extender.
        config()->set('capell-test.panel_builds', (int) config('capell-test.panel_builds', 0) + 1);

        return $panel->id('boot-registered');
    }
}
PHP);
    require_once $providerPath;

    // Mirror bootstrap: Filament shares one registry, and the provider registers
    // before that registry first resolves.
    app()->forgetInstance(PanelRegistry::class);
    app()->singleton(PanelRegistry::class);
    app()->register('App\\Providers\\Filament\\BootRegisteredTestPanelProvider');

    $bootstrapPanel = resolve(PanelRegistry::class)->get('boot-registered');

    expect($bootstrapPanel)->not->toBeNull()
        ->and(config('capell-test.panel_builds'))->toBe(1);

    InstallFilamentPanelAction::registerPanelProviders();

    expect(config('capell-test.panel_builds'))->toBe(1)
        ->and(resolve(PanelRegistry::class)->get('boot-registered'))->toBe($bootstrapPanel);
});

it('configures the compiled admin theme when panel colours are already configured', function (): void {
    $providerPath = app_path('Providers/Filament/FilamentInstallTestPanelProvider.php');
    File::ensureDirectoryExists(dirname($providerPath));
    File::put($providerPath, <<<'PHP'
<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Override;
class FilamentInstallTestPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('admin')->colors(['primary' => 'amber']);
    }
}
PHP);
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldNotReceive('all');
    $kernel->shouldNotReceive('call');
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    app()->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    InstallFilamentPanelAction::run(new NullProgressReporter);
    $firstPass = File::get($providerPath);
    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect($firstPass)->toContain("->viteTheme('resources/css/filament/admin/theme.css')");
    expect(File::get($providerPath))->toBe($firstPass);
    expect(File::exists(resource_path('css/filament/admin/theme.css')))->toBeTrue();
});

it('preserves explicit themes and customised panel methods', function (string $body, bool $expectsWarning): void {
    $path = app_path('Providers/Filament/FilamentInstallTestPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    $source = '<?php namespace App\\Providers\\Filament; class FilamentInstallTestPanelProvider { public function panel($panel) { ' . $body . ' } }';
    File::put($path, $source);
    $reporter = new RecordingInstallProgressReporter;

    InstallFilamentPanelAction::run($reporter);

    expect(File::get($path))->toBe($source);
    expect(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
    expect(in_array('→ Filament panel theme is not configured. Add ->viteTheme(...) or another theme configuration to your panel provider.', $reporter->lines, true))->toBe($expectsWarning);
})->with([
    'explicit stylesheet' => ['return $panel->id("admin")->theme("custom.css");', false],
    'customised method' => ['$panel->id("admin"); return $panel;', true],
]);
