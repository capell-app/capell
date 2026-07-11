<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class PendingHighPriorityStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.pending-high';
    }

    public function label(): string
    {
        return 'High';
    }

    #[Override]
    public function priority(): int
    {
        return 10;
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
