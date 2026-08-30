<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Contracts\Extensions\RegistersExtensionRenderHook;

final class ConformanceRenderHook implements RegistersExtensionRenderHook
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
