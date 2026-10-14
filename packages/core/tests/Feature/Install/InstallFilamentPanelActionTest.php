<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Actions\Runtime\BuildRuntimeRoleProviderManifestsAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Runtime\RuntimeRoleCachePaths;
use Capell\Core\Support\Runtime\RuntimeRoleProviderPolicy;
use Capell\Core\Tests\Support\Install\RecordingInstallProgressReporter;
use Capell\Tests\Support\GeneratedPhpFixture;
use Capell\Tests\Support\TailwindFixture;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\PanelRegistry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

it('preserves host panel provider bytes when an interrupted install is resumed', function (): void {
    $providerPath = app_path('Providers/Filament/ExistingHostPanelProvider.php');
    $providerContents = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Override;

final class ExistingHostPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->id('existing-host')->path('existing-host')->login()->brandName('Existing host panel');
    }
}
PHP;
    File::ensureDirectoryExists(dirname($providerPath));
    File::put($providerPath, $providerContents);

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldNotReceive('all');
    $kernel->shouldNotReceive('call');

    app()->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldNotReceive('make');

    app()->instance(ProcessFactoryInterface::class, $factory);

    $interruptedReporter = Mockery::mock(ProgressReporter::class);
    $interruptedReporter->shouldReceive('report')->once()->andThrow(new RuntimeException('Installation interrupted.'));

    expect(fn (): mixed => InstallFilamentPanelAction::run($interruptedReporter))
        ->toThrow(RuntimeException::class, 'Installation interrupted.');
    expect(File::get($providerPath))->toBe($providerContents);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(File::get($providerPath))->toBe($providerContents);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    $panel = GeneratedPhpFixture::load($providerPath, PanelProvider::class, app())->panel(Panel::make());
    expect(File::get($providerPath))->toBe($providerContents)
        ->and($panel->getId())->toBe('existing-host')
        ->and($panel->getPath())->toBe('existing-host')
        ->and($panel->hasLogin())->toBeTrue()
        ->and($panel->getBrandName())->toBe('Existing host panel')
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('configures the missing theme on an installer-owned panel', function (): void {
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
    $providerPath = app_path('Providers/Filament/FilamentInstallTestPanelProvider.php');
    InstallRunState::recordScaffoldedPanelProviders([$providerPath]);
    InstallFilamentPanelAction::run($reporter);
    $configured = File::get($providerPath);
    InstallFilamentPanelAction::run($reporter);
    $panel = GeneratedPhpFixture::load($providerPath, PanelProvider::class, app())->panel(Panel::make());

    expect($panel->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(File::get($providerPath))->toBe($configured)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeTrue();
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

    File::ensureDirectoryExists(app_path('Filament'));
    File::ensureDirectoryExists(resource_path('views/filament'));
    File::put(app_path('Filament/ThemeFixture.php'), 'p-7');
    File::put(resource_path('views/filament/theme-fixture.blade.php'), 'm-9');
    $compiled = TailwindFixture::build(resource_path('css/filament/admin/theme.css'));
    expect(preg_match('/\.p-7\s*\{/', $compiled))->toBe(1)
        ->and(preg_match('/\.m-9\s*\{/', $compiled))->toBe(1)
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

    expect(GeneratedPhpFixture::load($providerPath, PanelProvider::class, app())->panel(Panel::make())->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
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

it('preserves existing panel colours and bytes across repeated installs', function (): void {
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

    InstallRunState::recordScaffoldedPanelProviders([$providerPath]);
    InstallFilamentPanelAction::run(new NullProgressReporter);
    $configured = File::get($providerPath);
    InstallFilamentPanelAction::run(new NullProgressReporter);

    $panel = GeneratedPhpFixture::load($providerPath, PanelProvider::class, app())->panel(Panel::make());
    expect($panel->getColors()['primary'])->toBe('amber')
        ->and($panel->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(File::get($providerPath))->toBe($configured)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeTrue();
});

it('preserves explicit themes and customised panel methods', function (string $body, bool $expectsWarning): void {
    $path = app_path('Providers/Filament/FilamentInstallTestPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    $source = '<?php namespace App\\Providers\\Filament; class FilamentInstallTestPanelProvider { public function panel($panel) { ' . $body . ' } }';
    File::put($path, $source);
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    $reporter = new RecordingInstallProgressReporter;

    InstallFilamentPanelAction::run($reporter);

    expect(File::get($path))->toBe($source);
    expect(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
    expect(in_array('→ Filament panel theme is not configured. Add ->viteTheme(...) or another theme configuration to your panel provider.', $reporter->lines, true))->toBe($expectsWarning);
})->with([
    'explicit Vite theme' => ['return $panel->id("admin")->viteTheme("resources/css/custom.css");', false],
    'explicit stylesheet' => ['return $panel->id("admin")->theme("custom.css");', false],
    'customised method' => ['$panel->id("admin"); return $panel;', true],
]);

it('repairs scaffolding after an interrupted invocation using persisted provenance', function (): void {
    $path = app_path('Providers/Filament/ResumedPanelProvider.php');
    bindFilamentPanelInstallProcessFactory(false, '', 'Interrupted asset publication.', scaffoldPanel: false);
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->twice()->andReturn(['filament:install' => true]);
    $kernel->shouldReceive('call')->once()->andReturnUsing(function () use ($path): int {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '<?php namespace App\\Providers\\Filament; class ResumedPanelProvider extends \\Filament\\PanelProvider { #[\\Override] public function panel(\\Filament\\Panel $panel): \\Filament\\Panel { return $panel->id("resumed")->colors(["primary" => "amber"]); } }');

        return 19;
    });
    $kernel->shouldReceive('output')->andReturn('Interrupted after scaffolding.');
    app()->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    expect(fn (): mixed => InstallFilamentPanelAction::run(new NullProgressReporter))
        ->toThrow(RuntimeException::class, 'Interrupted asset publication.');
    expect(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();

    // Resume in another PHP process, with no shared container or cache instances.
    $resume = <<<'PHP'
require $argv[1];
$app = new \Illuminate\Foundation\Application($argv[2]);
$app->instance('config', new \Illuminate\Config\Repository(['cache' => ['prefix' => '']]));
\Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$app->register(\Illuminate\Filesystem\FilesystemServiceProvider::class);
$app->register(\Illuminate\Cache\CacheServiceProvider::class);
$action = new \Capell\Core\Actions\Install\InstallFilamentPanelAction(new \Capell\Core\Support\Process\SymfonyProcessFactory);
$action->handle(new \Capell\Core\Support\Install\NullProgressReporter);
PHP;
    // CI Testbench skeletons have no vendor directory; use this checkout's loader.
    $autoloadPath = dirname(__DIR__, 5) . '/vendor/autoload.php';
    expect($autoloadPath)->toBeFile()
        ->not->toBe($this->originalBasePath . '/vendor/autoload.php');
    $process = new SymfonyProcess([PHP_BINARY, '-r', $resume, $autoloadPath, $this->temporaryBasePath]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
    $configured = File::get($path);
    resolve(InstallFilamentPanelAction::class)->handle(new NullProgressReporter);
    $panel = GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make());
    expect($panel->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and($panel->getColors()['primary'])->toBe('amber')
        ->and(File::get($path))->toBe($configured)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeTrue();
});

it('checks theme configuration separately for each provider', function (): void {
    $themed = app_path('Providers/Filament/ThemedHostPanelProvider.php');
    $owned = app_path('Providers/Filament/CustomOwnedPanelProvider.php');
    File::ensureDirectoryExists(dirname($themed));
    File::put($themed, '<?php class ThemedHostPanelProvider { public function panel($panel) { return $panel->theme("custom.css"); } }');
    File::put($owned, '<?php class CustomOwnedPanelProvider { public function panel($panel) { $panel->id("workspace"); return $panel; } }');
    $hostBytes = File::get($themed);
    $ownedBytes = File::get($owned);
    InstallRunState::recordScaffoldedPanelProviders([$owned]);
    $reporter = new RecordingInstallProgressReporter;

    InstallFilamentPanelAction::run($reporter);

    expect($reporter->lines)->toContain('→ Filament panel theme is not configured. Add ->viteTheme(...) or another theme configuration to your panel provider.')
        ->and(File::get($themed))->toBe($hostBytes)
        ->and(File::get($owned))->toBe($ownedBytes)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('leaves an unrelated admin-id provider unchanged without scaffold provenance', function (): void {
    $path = app_path('Providers/Filament/AdminPanelProvider.php');
    $source = '<?php class AdminPanelProvider { public function panel($panel) { return $panel->id("admin"); } }';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $source);

    InstallFilamentPanelAction::run(new NullProgressReporter);
    expect(File::get($path))->toBe($source);
    resolve(InstallFilamentPanelAction::class)->handle(new NullProgressReporter);
    expect(File::get($path))->toBe($source)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('ignores theme mentions outside the returned panel configuration', function (string $chain, string $extraMethod): void {
    $path = app_path('Providers/Filament/MentionedThemePanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php namespace App\\Providers\\Filament; class MentionedThemePanelProvider extends \\Filament\\PanelProvider { #[\\Override] public function panel(\\Filament\\Panel $panel): \\Filament\\Panel { ' . $chain . ' } ' . $extraMethod . ' }');
    InstallRunState::recordScaffoldedPanelProviders([$path]);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    $panel = GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make());
    expect($panel->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
})->with([
    'comment' => ["// Later: ->viteTheme('custom.css')\nreturn \$panel->id('workspace');", ''],
    'string literal' => ['return $panel->id("workspace")->brandName("Example ->viteTheme(\'custom.css\')");', ''],
    'unrelated method' => ['return $panel->id("workspace");', 'public function preview($panel) { return $panel->viteTheme("custom.css"); }'],
    'deferred callback' => ['return $panel->id("workspace")->brandName(fn () => $panel->viteTheme("custom.css"));', ''],
]);

it('repairs the missing default stylesheet without rewriting an authorised provider', function (string $themeMethod): void {
    $path = app_path('Providers/Filament/DefaultThemePanelProvider.php');
    $source = '<?php namespace App\\Providers\\Filament; class DefaultThemePanelProvider { public function panel($panel) { return $panel->id("workspace")->' . $themeMethod . '("resources/css/filament/admin/theme.css"); } }';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $source);
    InstallRunState::recordScaffoldedPanelProviders([$path]);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(File::get($path))->toBe($source)
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
    $stylesheet = File::get(resource_path('css/filament/admin/theme.css'));
    InstallFilamentPanelAction::run(new NullProgressReporter);
    expect(File::get($path))->toBe($source)
        ->and(File::get(resource_path('css/filament/admin/theme.css')))->toBe($stylesheet);
})->with(['viteTheme', 'theme']);

it('does not give a replacement host provider ownership of a pending scaffold path', function (): void {
    $path = app_path('Providers/Filament/ReplacedPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php class ReplacedPanelProvider { public function panel($panel) { return $panel->id("scaffolded"); } }');
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    $replacement = '<?php class ReplacedPanelProvider { public function panel($panel) { return $panel->id("host")->brandName("Host replacement"); } }';
    File::put($path, $replacement);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(File::get($path))->toBe($replacement)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('retires pending scaffold provenance after the panel step succeeds', function (): void {
    $path = app_path('Providers/Filament/CompletedPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php class CompletedPanelProvider { public function panel($panel) { return $panel->id("admin"); } }');
    InstallRunState::recordScaffoldedPanelProviders([$path]);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(InstallRunState::scaffoldedPanelProviders())->toBe([]);
    $replacement = '<?php class CompletedPanelProvider { public function panel($panel) { return $panel->id("host"); } }';
    File::put($path, $replacement);
    InstallFilamentPanelAction::run(new NullProgressReporter);
    expect(File::get($path))->toBe($replacement);
});

it('does not infer ownership from legacy path-only or missing provenance', function (array $state): void {
    $path = app_path('Providers/Filament/LegacyPanelProvider.php');
    $source = '<?php class LegacyPanelProvider { public function panel($panel) { return $panel->id("host"); } }';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $source);
    Cache::build(['driver' => 'file', 'path' => base_path('storage/framework/cache/data')])->forever('capell.install.state', $state);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(File::get($path))->toBe($source)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
})->with([
    'missing key' => [[]],
    'path-only key' => [['scaffolded_panel_providers' => ['app/Providers/Filament/LegacyPanelProvider.php']]],
]);

it('repairs a missing default stylesheet when the panel argument has a different name', function (): void {
    $path = app_path('Providers/Filament/RenamedArgumentPanelProvider.php');
    $source = '<?php namespace App\\Providers\\Filament; class RenamedArgumentPanelProvider extends \\Filament\\PanelProvider { #[\\Override] public function panel(\\Filament\\Panel $workspace): \\Filament\\Panel { return $workspace->id("workspace")->viteTheme("resources/css/filament/admin/theme.css"); } }';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $source);
    InstallRunState::recordScaffoldedPanelProviders([$path]);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(File::get($path))->toBe($source)
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile()
        ->and(GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make())->getViteTheme())->toBe('resources/css/filament/admin/theme.css');
});

it('repairs a default stylesheet referenced by a conditional panel return', function (): void {
    $path = app_path('Providers/Filament/ConditionalThemePanelProvider.php');
    $source = '<?php namespace App\\Providers\\Filament; class ConditionalThemePanelProvider extends \\Filament\\PanelProvider { #[\\Override] public function panel(\\Filament\\Panel $panel): \\Filament\\Panel { if (config("app.debug")) { return $panel->id("workspace")->viteTheme("resources/css/filament/admin/theme.css"); } return $panel->id("workspace"); } }';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $source);
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    config(['app.debug' => true]);

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(File::get($path))->toBe($source)
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile()
        ->and(GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make())->getViteTheme())->toBe('resources/css/filament/admin/theme.css');
});

it('leaves the panel it scaffolds unthemed after the theme prompt was declined until the user opts in', function (): void {
    InstallRunState::rememberPanelThemeDecision(null, accepted: false);
    bindFilamentPanelInstallProcessFactory();
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->once()->andReturn([]);
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();
    $path = app_path('Providers/Filament/FilamentInstallTestPanelProvider.php');

    InstallFilamentPanelAction::run(new NullProgressReporter);
    $scaffolded = File::get($path);
    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(File::get($path))->toBe($scaffolded)
        ->and(GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make())->getViteTheme())->toBeNull()
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();

    InstallRunState::rememberPanelThemeDecision($path, accepted: true);
    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make())->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
});
