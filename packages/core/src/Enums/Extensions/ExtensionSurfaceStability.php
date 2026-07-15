<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Extensions;

enum ExtensionSurfaceStability: string
{
    case Stable = 'stable';
    case Experimental = 'experimental';
    case Internal = 'internal';
}
