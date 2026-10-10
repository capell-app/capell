<?php

declare(strict_types=1);

namespace Vendor\ExtensionConformance;

use Capell\Core\Contracts\Extensions\RegistersExtensionRenderHook;
use Override;

final class ConformanceRenderHook implements RegistersExtensionRenderHook
{
    #[Override]
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
