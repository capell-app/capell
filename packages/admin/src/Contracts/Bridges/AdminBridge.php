<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Bridges;

use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;

interface AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool;

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void;
}
