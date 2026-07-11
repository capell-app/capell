<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;

final class DisabledBridgeForRegistryTest implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return false;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        //
    }
}
