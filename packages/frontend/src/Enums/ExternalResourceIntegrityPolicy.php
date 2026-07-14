<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum ExternalResourceIntegrityPolicy: string
{
    case Off = 'off';
    case Warn = 'warn';
    case Require = 'require';
}
