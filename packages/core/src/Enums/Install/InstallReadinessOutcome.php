<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Install;

enum InstallReadinessOutcome: string
{
    case AutomatedNow = 'automated-now';
    case Queued = 'queued';
    case DeployTime = 'deploy-time';
    case Manual = 'manual';
    case Blocked = 'blocked';
}
