<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Fixtures;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use RuntimeException;

final class FailingLateAdminRuntimeActivatorTestBridge implements AdminBridge
{
    public static int $registrations = 0;

    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        self::$registrations++;

        throw new RuntimeException('Late bridge registration failed.');
    }
}
