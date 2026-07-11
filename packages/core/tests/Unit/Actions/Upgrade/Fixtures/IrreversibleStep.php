<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;

class IrreversibleStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.irreversible';
    }

    public function label(): string
    {
        return 'Irreversible';
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
