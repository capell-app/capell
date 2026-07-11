<?php

declare(strict_types=1);

use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistry;
use Capell\Admin\Tests\Fixtures\Autoload\DisabledBridgeForRegistryTest;
use Capell\Admin\Tests\Fixtures\Autoload\EnabledBridgeForRegistryTest;
use Capell\Admin\Tests\Fixtures\Autoload\OtherBridgeForRegistryTest;

it('registers bridge classes once per package and resolves enabled bridge instances', function (): void {
    $registry = new AdminBridgeRegistry;

    $registry->register('capell-app/test', EnabledBridgeForRegistryTest::class);
    $registry->register('capell-app/test', EnabledBridgeForRegistryTest::class);
    $registry->register('capell-app/test', DisabledBridgeForRegistryTest::class);
    $registry->register('capell-app/other', OtherBridgeForRegistryTest::class);

    expect($registry->classes('capell-app/test'))->toBe([
        EnabledBridgeForRegistryTest::class,
        DisabledBridgeForRegistryTest::class,
    ]);

    $bridges = $registry->enabledBridges(AdminBridgeContextData::forPackage('capell-app/test'));

    expect($bridges)
        ->toHaveCount(1)
        ->and($bridges[0])->toBeInstanceOf(EnabledBridgeForRegistryTest::class);
});

it('clears registered bridge classes', function (): void {
    $registry = new AdminBridgeRegistry;

    $registry->register('capell-app/test', EnabledBridgeForRegistryTest::class);
    $registry->clear();

    expect($registry->classes('capell-app/test'))->toBe([]);
});
