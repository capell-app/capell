<?php

declare(strict_types=1);

use Capell\Core\Data\PackageData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Support\Packages\PackageLifecycleRunner;
use Capell\Core\Tests\Support\Fixtures\Autoload\InvalidLifecycleAction;
use Capell\Core\Tests\Support\Fixtures\Autoload\LifecycleRecorderAction;
use Illuminate\Support\Facades\Artisan;

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
