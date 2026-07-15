<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Tests\Support\Fixtures\Autoload\InvalidLifecycleAction;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    LifecycleRecorderAction::reset();
});

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
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(null)->andReturnSelf();
    $process->shouldReceive('run')->once()->with(Mockery::type('callable'))->andReturn(0);
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();

    $factory = Mockery::mock(ProcessFactoryInterface::class);
    $factory->shouldReceive('make')
        ->once()
        ->withArgs(function (array $command, string $workingDirectory, ?array $environment = null): bool {
            $expectedEnvironment = str_contains(base_path(), 'testbench-skeletons')
                ? ['TESTBENCH_WORKING_PATH' => dirname(base_path(), 4)]
                : null;

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
