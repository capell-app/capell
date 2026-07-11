<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class DependencyBaseStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.dependency-base';
    }

    public function label(): string
    {
        return 'Dependency base';
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
