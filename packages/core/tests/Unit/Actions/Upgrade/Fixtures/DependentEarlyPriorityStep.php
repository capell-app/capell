<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class DependentEarlyPriorityStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.dependent-early';
    }

    public function label(): string
    {
        return 'Dependent early';
    }

    #[Override]
    public function priority(): int
    {
        return 10;
    }

    /** @return array<int, string> */
    #[Override]
    public function dependsOn(): array
    {
        return ['core.dependency-base'];
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
