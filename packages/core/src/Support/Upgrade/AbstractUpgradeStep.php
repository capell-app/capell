<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Contracts\UpgradeStepContract;
use Capell\Core\Data\UpgradeContext;

abstract class AbstractUpgradeStep implements UpgradeStepContract
{
    abstract public function id(): string;

    abstract public function label(): string;

    abstract public function run(UpgradeContext $context): bool;

    public function package(): string
    {
        return 'capell-app/capell';
    }

    public function priority(): int
    {
        return 100;
    }

    /** @return array<int, string> */
    public function dependsOn(): array
    {
        return [];
    }

    public function shouldRun(UpgradeContext $context): bool
    {
        return true;
    }

    public function rollback(UpgradeContext $context): bool
    {
        return false;
    }
}
