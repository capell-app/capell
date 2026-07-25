<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Support\Fixtures;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;

final class RegisteringAdminRuntimeActivatorTestBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->bridge('vendor/nested', NestedAdminRuntimeActivatorTestBridge::class);
    }
}
