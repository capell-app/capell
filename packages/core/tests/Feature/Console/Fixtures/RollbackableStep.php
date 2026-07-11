<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Console\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class RollbackableStep extends AbstractUpgradeStep
{
    public static int $rollbacks = 0;

    public function id(): string
    {
        return 'core.rollback-cli';
    }

    public function label(): string
    {
        return 'CLI rollback';
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }

    #[Override]
    public function rollback(UpgradeContext $context): bool
    {
        self::$rollbacks++;

        return true;
    }
}
