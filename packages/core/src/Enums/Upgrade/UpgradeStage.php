<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Upgrade;

enum UpgradeStage: string
{
    case Readiness = 'readiness';
    case Queue = 'queue';
    case Migrations = 'migrations';
    case UpgradeSteps = 'upgrade_steps';
    case LegacyPackageCommands = 'legacy_package_commands';
    case VersionLedger = 'version_ledger';
    case CacheClear = 'cache_clear';
    case Complete = 'complete';
    case Failed = 'failed';
}
