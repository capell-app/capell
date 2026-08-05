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

it('refuses a web triggered removal when server side tooling is disabled', function (): void {
    config()->set('capell.release_root_mode', 'mutable');
    config()->set('capell.server_side_tooling', false);

    // An unattended, web-triggered Composer write is exactly what
    // CAPELL_SERVER_SIDE_TOOLING gates on the install side. Without the flag a
    // host that refuses a web-triggered install would still permit a
    // web-triggered removal.
    expect(fn (): array => RemovePackageAction::run('vendor/package', requiresServerSideTooling: true))
        ->toThrow(RuntimeException::class, 'CAPELL_SERVER_SIDE_TOOLING is disabled');
});

it('names the operation the requirement performs when it refuses', function (): void {
    config()->set('capell.release_root_mode', 'immutable');

    expect(fn (): array => RequirePackageAction::run('vendor/package:^1.0'))
        ->toThrow(RuntimeException::class, 'Installing a package with Composer is blocked');
});
