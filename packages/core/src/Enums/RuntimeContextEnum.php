<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

enum RuntimeContextEnum: string
{
    case Admin = 'admin';
    case Auth = 'auth';
    case Frontend = 'frontend';
    case Console = 'console';
    case Shared = 'shared';
}
