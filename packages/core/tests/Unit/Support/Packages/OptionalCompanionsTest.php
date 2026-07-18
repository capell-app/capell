<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Packages\OptionalCompanions;
use Illuminate\Container\Container;

it('requires installed state and every requested class', function (): void {
    $core = Mockery::mock(CapellCoreManager::class);
    $core->shouldReceive('isPackageInstalled')
        ->twice()
        ->with('capell-app/example')
        ->andReturn(true);

    $companions = new OptionalCompanions($core, new Container);

    expect($companions->installed('capell-app/example', [stdClass::class]))->toBeTrue()
        ->and($companions->installed('capell-app/example', ['Capell\\Missing\\CompanionClass']))->toBeFalse();
});

it('does not inspect classes for an uninstalled companion', function (): void {
    $core = Mockery::mock(CapellCoreManager::class);
    $core->shouldReceive('isPackageInstalled')
        ->once()
        ->with('capell-app/example')
        ->andReturnFalse();

    expect(new OptionalCompanions($core, new Container)->installed(
        'capell-app/example',
        ['Capell\\Missing\\CompanionClass'],
    ))->toBeFalse();
});

it('resolves bound companion services and returns null when absent', function (): void {
    $core = Mockery::mock(CapellCoreManager::class);
    $container = new Container;
    $service = new stdClass;
    $container->instance(stdClass::class, $service);

    $companions = new OptionalCompanions($core, $container);

    expect($companions->service(stdClass::class))->toBe($service)
        ->and($companions->service('Capell\\Missing\\CompanionContract'))->toBeNull();
});

it('exposes companion resolution through the core facade', function (): void {
    $service = new stdClass;
    app()->instance(stdClass::class, $service);

    expect(CapellCore::companionInstalled('capell-app/missing', [stdClass::class]))->toBeFalse()
        ->and(CapellCore::companionService(stdClass::class))->toBe($service)
        ->and(CapellCore::companionService('Capell\\Missing\\CompanionContract'))->toBeNull();
});
