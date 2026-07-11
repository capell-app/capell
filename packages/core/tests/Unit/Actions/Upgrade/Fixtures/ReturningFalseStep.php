<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;

class ReturningFalseStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.test.returning-false';
    }

    public function label(): string
    {
        return 'Returns false';
    }

    public function run(UpgradeContext $context): bool
    {
        return false;
    }
}
