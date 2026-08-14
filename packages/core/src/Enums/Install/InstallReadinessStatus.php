<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Install;

enum InstallReadinessStatus: string
{
    case Passed = 'passed';
    case Warning = 'warning';
    case Blocked = 'blocked';
}
