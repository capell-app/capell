<?php

declare(strict_types=1);

use Capell\Core\Actions\RemovePackageAction;
use Capell\Core\Actions\RequirePackageAction;
use Capell\Core\Support\Process\ProcessFactoryInterface;

beforeEach(function (): void {
    // Any attempt to shell out to Composer is a failure of the guard, so the
    // factory is left un-stubbed: resolving it would produce a real process.
    $refusingFactory = Mockery::mock(ProcessFactoryInterface::class);
    $refusingFactory->shouldNotReceive('make');

    app()->instance(ProcessFactoryInterface::class, $refusingFactory);

    RequirePackageAction::setProcessFactory(function (): never {
        throw new RuntimeException('Composer must not run when the release root refuses writes.');
    });
});

afterEach(function (): void {
    RequirePackageAction::resetProcessFactory();
});

it('refuses to remove a package from a release root the deployment declared immutable', function (string $mode): void {
    config()->set('capell.release_root_mode', $mode);

    expect(fn (): array => RemovePackageAction::run('vendor/package'))
        ->toThrow(RuntimeException::class, 'CAPELL_RELEASE_ROOT_MODE is ' . $mode);
})->with(['immutable', 'atomic']);

it('refuses to require a package into a release root the deployment declared immutable', function (string $mode): void {
    config()->set('capell.release_root_mode', $mode);

    expect(fn (): array => RequirePackageAction::run('vendor/package:^1.0'))
        ->toThrow(RuntimeException::class, 'CAPELL_RELEASE_ROOT_MODE is ' . $mode);
})->with(['immutable', 'atomic']);

it('names the bootstrap cache the removal writes to when it refuses', function (): void {
    config()->set('capell.release_root_mode', 'immutable');

    expect(fn (): array => RemovePackageAction::run('vendor/package'))
        ->toThrow(RuntimeException::class, 'Removing a package with Composer is blocked');
});

it('names the operation the requirement performs when it refuses', function (): void {
    config()->set('capell.release_root_mode', 'immutable');

    expect(fn (): array => RequirePackageAction::run('vendor/package:^1.0'))
        ->toThrow(RuntimeException::class, 'Installing a package with Composer is blocked');
});
