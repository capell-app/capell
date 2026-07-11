<?php

declare(strict_types=1);

use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Tests\Fixtures\Autoload\TestBridgeForAdminBridgeBootTest;

beforeEach(function (): void {
    TestBridgeForAdminBridgeBootTest::$registeredPackageNames = [];
});

it('boots registered admin bridges once per package and passes package context', function (): void {
    CapellAdmin::registerAdminBridge('capell-app/test', TestBridgeForAdminBridgeBootTest::class);

    CapellAdmin::bootAdminBridges('capell-app/test');
    CapellAdmin::bootAdminBridges('capell-app/test');

    expect(TestBridgeForAdminBridgeBootTest::$registeredPackageNames)->toBe(['capell-app/test']);
});
