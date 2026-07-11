<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;

final class TestBridgeForAdminBridgeBootTest implements AdminBridge
{
    /** @var list<string> */
    public static array $registeredPackageNames = [];

    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        self::$registeredPackageNames[] = $context->packageName;
    }
}
