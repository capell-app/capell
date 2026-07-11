<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;

class TrackedStep extends AbstractUpgradeStep
{
    public int $runCount = 0;

    public function id(): string
    {
        return 'core.test.tracked';
    }

    public function label(): string
    {
        return 'Tracked';
    }

    public function run(UpgradeContext $context): bool
    {
        $this->runCount++;

        return true;
    }
}
