<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Support\Fixtures\Autoload\InvalidLifecycleAction;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Illuminate\Support\Facades\Artisan;

use function Orchestra\Testbench\package_path;

use Symfony\Component\Process\Process;

beforeEach(function (): void {
    LifecycleRecorderAction::reset();
});

/**
 * @return array<string, string>|null
 */
function expectedFreshProcessEnvironment(): ?array
{
    $basePath = str_replace('\\', '/', base_path());
    $isTestbenchApplication = str_contains($basePath, 'testbench-skeletons')
        || str_contains($basePath, '/vendor/orchestra/testbench-core/laravel');

    return $isTestbenchApplication
        ? ['TESTBENCH_WORKING_PATH' => package_path()]
        : null;
}

it('runs lifecycle actions without requiring artisan command registration', function (): void {
    $package = new PackageData(
        name: 'vendor/action-package',
        type: PackageTypeEnum::Plugin,
    );

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:missing-install-command',
        actionClass: LifecycleRecorderAction::class,
        arguments: ['--force' => true],
        allowLegacyCommand: false,
    );

    expect(LifecycleRecorderAction::$calls)->toBe([
        [
            'package' => 'vendor/action-package',
            'arguments' => ['--force' => true],
        ],
    ]);
});

it('prefers lifecycle actions over legacy commands when fallback is allowed', function (): void {
    $legacyCommandRan = false;

    Artisan::command('vendor:legacy-install', function () use (&$legacyCommandRan): int {
        $legacyCommandRan = true;

        return 0;
    });

    $package = new PackageData(
        name: 'vendor/action-first-package',
        type: PackageTypeEnum::Plugin,
    );

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:legacy-install',
        actionClass: LifecycleRecorderAction::class,
        allowLegacyCommand: true,
    );

    expect($legacyCommandRan)->toBeFalse()
        ->and(LifecycleRecorderAction::$calls)->toHaveCount(1);
});

it('blocks legacy command only packages when fallback is not allowed', function (): void {
    $package = new PackageData(
        name: 'vendor/legacy-only-package',
        type: PackageTypeEnum::Plugin,
    );

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:legacy-install',
        actionClass: null,
        allowLegacyCommand: false,
    );
})->throws(RuntimeException::class, 'web-triggered package lifecycle work must use a lifecycle Action');

it('falls back to legacy commands when fallback is allowed', function (): void {
    $legacyCommandRan = false;

    Artisan::command('vendor:legacy-fallback-install', function () use (&$legacyCommandRan): int {
        $legacyCommandRan = true;

        return 0;
    });

    $package = new PackageData(
        name: 'vendor/legacy-package',
        type: PackageTypeEnum::Plugin,
    );

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:legacy-fallback-install',
        actionClass: null,
        allowLegacyCommand: true,
    );

    expect($legacyCommandRan)->toBeTrue();
});

it('runs a dynamically installed package command in a fresh process when the current artisan application cannot see it', function (): void {
    $package = new PackageData(
        name: 'vendor/dynamic-package',
        type: PackageTypeEnum::Plugin,
    );
    $probeProcess = Mockery::mock(Process::class);
    $probeProcess->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $probeProcess->shouldReceive('run')->once()->with()->andReturn(0);
    $probeProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $probeProcess->shouldReceive('getOutput')->once()->andReturn("vendor:dynamic-install  Installs the dynamic package\n");

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $process->shouldReceive('run')->once()->with(Mockery::type('callable'))->andReturn(0);
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->ordered()
        ->withArgs(function (array $command, string $workingDirectory, ?array $environment = null): bool {
            $expectedEnvironment = expectedFreshProcessEnvironment();

            return $command === [
                PHP_BINARY,
                base_path('artisan'),
                'list',
                '--raw',
                '--no-interaction',
            ]
                && $workingDirectory === base_path()
                && $environment === $expectedEnvironment;
        })
        ->andReturn($probeProcess);
    $factory->shouldReceive('make')
        ->once()
        ->ordered()
        ->withArgs(function (array $command, string $workingDirectory, ?array $environment = null): bool {
            $expectedEnvironment = expectedFreshProcessEnvironment();

            return $command === [
                PHP_BINARY,
                base_path('artisan'),
                'vendor:dynamic-install',
                '--no-interaction',
                '--force',
            ]
                && $workingDirectory === base_path()
                && $environment === $expectedEnvironment;
        })
        ->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);
    config(['capell-installer.php_binary' => PHP_BINARY]);

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:dynamic-install',
        actionClass: null,
        arguments: ['--force' => true],
        allowLegacyCommand: true,
    );
});

it('runs a visible lifecycle command in a fresh process when the caller requires a clean application boot', function (): void {
    Artisan::command('vendor:visible-install', fn (): int => 0);

    $package = new PackageData(
        name: 'vendor/application-mutating-package',
        type: PackageTypeEnum::Plugin,
    );
    $probeProcess = Mockery::mock(Process::class);
    $probeProcess->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $probeProcess->shouldReceive('run')->once()->with()->andReturn(0);
    $probeProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $probeProcess->shouldReceive('getOutput')->once()->andReturn("vendor:visible-install  Runs the visible install command\n");

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $process->shouldReceive('run')->once()->with(Mockery::type('callable'))->andReturn(0);
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->ordered()
        ->withArgs(fn (array $command, string $workingDirectory): bool => $command === [
            PHP_BINARY,
            base_path('artisan'),
            'list',
            '--raw',
            '--no-interaction',
        ] && $workingDirectory === base_path())
        ->andReturn($probeProcess);
    $factory->shouldReceive('make')
        ->once()
        ->ordered()
        ->withArgs(fn (array $command, string $workingDirectory): bool => $command === [
            PHP_BINARY,
            base_path('artisan'),
            'vendor:visible-install',
            '--no-interaction',
            '--force',
        ] && $workingDirectory === base_path())
        ->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);
    config(['capell-installer.php_binary' => PHP_BINARY]);

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:visible-install',
        actionClass: null,
        arguments: ['--force' => true],
        allowLegacyCommand: true,
        freshProcess: true,
    );
});

it('runs the real command after a probe that confirms the command is registered', function (): void {
    $package = new PackageData(
        name: 'vendor/probed-package',
        type: PackageTypeEnum::Plugin,
    );
    $probeProcess = Mockery::mock(Process::class);
    $probeProcess->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $probeProcess->shouldReceive('run')->once()->with()->andReturn(0);
    $probeProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $probeProcess->shouldReceive('getOutput')->once()->andReturn("vendor:probed-install  Installs the probed package\n");

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $process->shouldReceive('run')->once()->with(Mockery::type('callable'))->andReturn(0);
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->ordered()->andReturn($probeProcess);
    $factory->shouldReceive('make')->once()->ordered()->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);
    config(['capell-installer.php_binary' => PHP_BINARY]);

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:probed-install',
        actionClass: null,
        allowLegacyCommand: true,
        freshProcess: true,
    );
});

it('throws does-not-exist and never runs the real command when the probe output omits the command', function (): void {
    $package = new PackageData(
        name: 'vendor/missing-command-package',
        type: PackageTypeEnum::Plugin,
    );
    $probeProcess = Mockery::mock(Process::class);
    $probeProcess->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $probeProcess->shouldReceive('run')->once()->with()->andReturn(0);
    $probeProcess->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $probeProcess->shouldReceive('getOutput')->once()->andReturn("vendor:other-install  Some other command\n");

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($probeProcess);
    app()->instance(ProcessFactoryInterface::class, $factory);
    config(['capell-installer.php_binary' => PHP_BINARY]);

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:missing-install',
        actionClass: null,
        allowLegacyCommand: true,
        freshProcess: true,
    );
})->throws(RuntimeException::class, "Install command 'vendor:missing-install' does not exist.");

it('runs the real command and surfaces its own failure when the probe itself fails to boot', function (): void {
    $package = new PackageData(
        name: 'vendor/probe-failure-package',
        type: PackageTypeEnum::Plugin,
    );
    $probeProcess = Mockery::mock(Process::class);
    $probeProcess->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $probeProcess->shouldReceive('run')->once()->with()->andReturn(1);
    $probeProcess->shouldReceive('isSuccessful')->once()->andReturnFalse();

    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $process->shouldReceive('run')->once()->with(Mockery::type('callable'))->andReturn(0);
    $process->shouldReceive('isSuccessful')->once()->andReturnFalse();
    $process->shouldReceive('getErrorOutput')->once()->andReturn('boot failure');
    $process->shouldReceive('getExitCode')->once()->andReturn(3);

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')->once()->ordered()->andReturn($probeProcess);
    $factory->shouldReceive('make')->once()->ordered()->andReturn($process);
    app()->instance(ProcessFactoryInterface::class, $factory);
    config(['capell-installer.php_binary' => PHP_BINARY]);

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: 'vendor:boot-failure-install',
        actionClass: null,
        allowLegacyCommand: true,
        freshProcess: true,
    );
})->throws(RuntimeException::class, "Install command 'vendor:boot-failure-install' failed in a fresh process with exit code 3. boot failure");

it('rejects lifecycle classes that do not implement the package lifecycle contract', function (): void {
    $package = new PackageData(
        name: 'vendor/invalid-action-package',
        type: PackageTypeEnum::Plugin,
    );

    resolve(PackageLifecycleRunner::class)->run(
        package: $package,
        phase: 'install',
        command: null,
        actionClass: InvalidLifecycleAction::class,
    );
})->throws(RuntimeException::class, 'must implement');
