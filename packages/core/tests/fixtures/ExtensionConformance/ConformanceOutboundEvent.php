<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Contracts\Extensions\RegistersExtensionOutboundEvent;

final class ConformanceOutboundEvent implements RegistersExtensionOutboundEvent
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
