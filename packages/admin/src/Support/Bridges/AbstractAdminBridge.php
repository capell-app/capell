<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Bridges;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;

abstract class AbstractAdminBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }
}
