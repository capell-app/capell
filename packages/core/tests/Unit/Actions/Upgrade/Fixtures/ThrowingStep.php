<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use RuntimeException;

class ThrowingStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.test.throwing';
    }

    public function label(): string
    {
        return 'Throws';
    }

    public function run(UpgradeContext $context): bool
    {
        throw new RuntimeException('kaboom');
    }
}
