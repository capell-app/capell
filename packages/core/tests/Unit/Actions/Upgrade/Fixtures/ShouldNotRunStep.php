<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class ShouldNotRunStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.should-not-run';
    }

    public function label(): string
    {
        return 'Skip me';
    }

    #[Override]
    public function shouldRun(UpgradeContext $context): bool
    {
        return false;
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
