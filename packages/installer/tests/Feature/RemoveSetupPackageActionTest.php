<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Installer\Actions\RemoveSetupPackageAction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 4) . '/tests/Support/InstallFilesystemLock.php';

function bindRemoveSetupPackageProcessFactory(): void
{
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();

    $process = Mockery::mock(Process::class);
    $process
        ->shouldReceive('setEnv')
        ->with(Mockery::on(fn (array $environment): bool => ($environment['GIT_CONFIG_KEY_0'] ?? null) === 'safe.directory'
            && ($environment['GIT_CONFIG_VALUE_0'] ?? null) === '*'))
        ->andReturnSelf();
    $process
        ->shouldReceive('setTimeout')
        ->with(300)
        ->andReturnSelf();
    $process
        ->shouldReceive('run')
        ->once()
        ->andReturn(0);
    $process
        ->shouldReceive('getErrorOutput')
        ->andReturn('');
    $process
        ->shouldReceive('getOutput')
        ->andReturn('Package capell-app/installer removed');
    $process
        ->shouldReceive('isSuccessful')
        ->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory
        ->shouldReceive('make')
        ->once()
        ->with(
            Mockery::on(fn (array|string $command): bool => $command === ['composer', 'remove', 'capell-app/installer', '--no-interaction', '--no-scripts']),
            Mockery::type('string'),
        )
        ->andReturn($process);

    app()->instance(ProcessFactoryInterface::class, $factory);
}

it('clears cached filament panel components when removing the installer package', function (): void {
    bindRemoveSetupPackageProcessFactory();

    $cachedPanelPath = base_path('bootstrap/cache/filament/panels/admin.php');

    File::ensureDirectoryExists(dirname($cachedPanelPath));
    File::put($cachedPanelPath, '<?php return ["pages" => ["Capell\\\\Installer\\\\Filament\\\\Pages\\\\InstallCapellPage"]];');

    expect(RemoveSetupPackageAction::run())->toBe(url('/admin'));

    expect(File::exists($cachedPanelPath))->toBeFalse();
});

it('still removes the installer package when Filament cache clearing is unavailable', function (): void {
    bindRemoveSetupPackageProcessFactory();

    $originalArtisan = resolve(Kernel::class);

    Artisan::swap(new class
    {
        public function call(string $command): int
        {
            throw new RuntimeException('command unavailable');
        }
    });

    try {
        expect(RemoveSetupPackageAction::run())->toBe(url('/admin'));
    } finally {
        Artisan::swap($originalArtisan);
    }
});

it('falls back to the public homepage when the admin package lookup is unavailable', function (): void {
    bindRemoveSetupPackageProcessFactory();

    CapellCore::shouldReceive('isPackageInstalled')
        ->with('capell-app/admin')
        ->andThrow(new RuntimeException('package registry unavailable'));
    CapellCore::shouldReceive('clearExtensionCache')
        ->once();

    expect(RemoveSetupPackageAction::run())->toBe(url('/'));
});
