<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;

class AlreadyAppliedStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.already-done';
    }

    public function label(): string
    {
        return 'Done';
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
