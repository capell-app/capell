<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Tests\Fixtures\Autoload\TestBridgeForAdminBridgeBootTest;
use Illuminate\Container\Container;

beforeEach(function (): void {
    TestBridgeForAdminBridgeBootTest::$registeredPackageNames = [];
});

it('boots registered admin bridges once per package and passes package context', function (): void {
    CapellAdmin::registerAdminBridge('capell-app/test', TestBridgeForAdminBridgeBootTest::class);

    CapellAdmin::bootAdminBridges('capell-app/test');
    CapellAdmin::bootAdminBridges('capell-app/test');

    expect(TestBridgeForAdminBridgeBootTest::$registeredPackageNames)->toBe(['capell-app/test']);
});

it('routes facade bridge registration through the container-owned registry', function (): void {
    $registry = resolve(AdminBridgeRegistry::class);

    CapellAdmin::registerAdminBridge('capell-app/test', TestBridgeForAdminBridgeBootTest::class);

    expect(resolve(AdminBridgeRegistry::class))->toBe($registry)
        ->and($registry->classes('capell-app/test'))->toBe([TestBridgeForAdminBridgeBootTest::class]);
});

it('keeps bridge boot metadata across scoped lifecycle resets', function (): void {
    $registry = resolve(AdminBridgeRegistry::class);
    $registrar = resolve(AdminBridgeRegistrar::class);
    $registrar->bridge('capell-app/test', TestBridgeForAdminBridgeBootTest::class);

    Container::getInstance()->forgetScopedInstances();

    expect(resolve(AdminBridgeRegistry::class))->toBe($registry)
        ->and(resolve(AdminBridgeRegistrar::class))->toBe($registrar)
        ->and($registry->classes('capell-app/test'))->toBe([TestBridgeForAdminBridgeBootTest::class]);

    CapellAdmin::bootAdminBridges('capell-app/test');
    CapellAdmin::bootAdminBridges('capell-app/test');

    expect(TestBridgeForAdminBridgeBootTest::$registeredPackageNames)->toBe(['capell-app/test']);
});
