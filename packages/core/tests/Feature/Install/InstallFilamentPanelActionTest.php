<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Support\Install\RecordingInstallProgressReporter;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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

function bindFilamentPanelInstallProcessFactory(bool $successful = true, string $output = 'Filament panel installed', string $errorOutput = ''): void
{
    $process = Mockery::mock(SymfonyProcess::class);
    $process
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();
    $process
        ->shouldReceive('run')
        ->once()
        ->andReturnUsing(function (?callable $callback = null) use ($successful, $output): int {
            if ($successful) {
                File::ensureDirectoryExists(app_path('Providers/Filament'));
                File::put(app_path('Providers/Filament/AdminPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
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
                '--panels',
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
    File::put(app_path('Providers/Filament/AdminPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
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

    InstallFilamentPanelAction::run(new NullProgressReporter);
});

it('reports when an existing filament panel is missing theme configuration', function (): void {
    File::ensureDirectoryExists(app_path('Providers/Filament'));
    File::put(app_path('Providers/Filament/AdminPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
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
        '→ Filament panel theme is not configured. Add ->viteTheme(...) or another theme configuration to your panel provider.',
    );
});

it('runs filament install with panel scaffolding when no panel provider exists', function (): void {
    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->once()->andReturn(['filament:install' => true]);
    $kernel->shouldReceive('call')->once()->with('filament:install', [
        '--panels' => true,
        '--no-interaction' => true,
    ])->andReturnUsing(function (): int {
        File::ensureDirectoryExists(app_path('Providers/Filament'));
        File::put(app_path('Providers/Filament/AdminPanelProvider.php'), <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
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

    InstallFilamentPanelAction::run(new NullProgressReporter);
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

    expect(file_exists(app_path('Providers/Filament/AdminPanelProvider.php')))->toBeTrue()
        ->and($reporter->lines)->toContain('Filament panel installed');
});

it('falls back to a fresh process when in-process filament install fails', function (): void {
    bindFilamentPanelInstallProcessFactory();

    $kernel = Mockery::mock(ConsoleKernel::class);
    $kernel->shouldReceive('all')->once()->andReturn(['filament:install' => true]);
    $kernel->shouldReceive('call')->once()->andThrow(new RuntimeException('Filament command failed.'));
    $kernel->shouldReceive('output')->zeroOrMoreTimes()->andReturn('');
    $this->app->instance(ConsoleKernel::class, $kernel);
    Artisan::clearResolvedInstances();

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(file_exists(app_path('Providers/Filament/AdminPanelProvider.php')))->toBeTrue();
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
