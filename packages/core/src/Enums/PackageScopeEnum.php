<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum PackageScopeEnum: string
{
    case Backend = 'backend';

    case Frontend = 'frontend';
}
