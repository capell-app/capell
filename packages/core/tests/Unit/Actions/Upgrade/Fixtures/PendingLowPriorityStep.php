<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class PendingLowPriorityStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.pending-low';
    }

    public function label(): string
    {
        return 'Low';
    }

    #[Override]
    public function priority(): int
    {
        return 200;
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
