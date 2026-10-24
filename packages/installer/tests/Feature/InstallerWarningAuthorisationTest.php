<?php

declare(strict_types=1);

use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Installer\Livewire\InstallerWarning;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 4) . '/tests/Support/InstallFilesystemLock.php';

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    $this->originalInstallerWarningArtisan = Artisan::getFacadeRoot();
    $this->installerWarningArtisan = new class
    {
        /** @var list<string> */
        public array $commands = [];

        public function call(string $command): int
        {
            $this->commands[] = $command;

            return 0;
        }
    };
    Artisan::swap($this->installerWarningArtisan);
});

afterEach(function (): void {
    Artisan::swap($this->originalInstallerWarningArtisan);
});

it('refuses installer removal for an ordinary panel actor even when tooling is enabled', function (): void {
    test()->actingAsUser();
    config(['capell.server_side_tooling' => true]);
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->never();
    app()->instance(ProcessFactoryInterface::class, $factory);
    $action = (new InstallerWarning)->deleteInstallerAction();

    expect($action->isAuthorized())->toBeFalse();
    expect(fn (): mixed => $action->call())->toThrow(HttpException::class);
    expect($this->installerWarningArtisan->commands)->toBe([]);
});

it('refuses installer removal for a global administrator while server tooling is disabled', function (): void {
    test()->actingAsAdmin();
    config(['capell.server_side_tooling' => false]);
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->never();
    app()->instance(ProcessFactoryInterface::class, $factory);
    $action = (new InstallerWarning)->deleteInstallerAction();

    expect($action->isAuthorized())->toBeFalse();
    expect(fn (): mixed => $action->call())->toThrow(HttpException::class);
    expect($this->installerWarningArtisan->commands)->toBe([]);
});

it('allows an authorised administrator to remove the installer with the tooling guard required', function (): void {
    test()->actingAsAdmin();
    config(['capell.server_side_tooling' => true]);
    preserveTestbenchPackageManifestFilesDuringPackageRemoval();
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setEnv')->with(Mockery::type('array'))->andReturnSelf();
    $process->shouldReceive('setTimeout')->with(capellComposerTimeoutSeconds())->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturn(0);
    $process->shouldReceive('getErrorOutput')->andReturn('');
    $process->shouldReceive('getOutput')->andReturn('Package capell-app/installer removed');
    $process->shouldReceive('isSuccessful')->andReturnTrue();
    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()
        ->with([...capellComposerArgv(), 'remove', 'capell-app/installer', '--no-interaction', '--no-scripts', '--no-audit', '--no-progress'], base_path())
        ->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);
    $action = (new InstallerWarning)->deleteInstallerAction();

    expect($action->isAuthorized())->toBeTrue();
    expect($action->call()->getTargetUrl())->toBe(url('/admin'))
        ->and($this->installerWarningArtisan->commands)->toBe(['filament:clear-cached-components']);
});
