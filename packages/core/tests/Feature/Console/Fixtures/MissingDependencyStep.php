<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Console\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class MissingDependencyStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.missing-dependency-step';
    }

    public function label(): string
    {
        return 'Missing dependency step';
    }

    #[Override]
    public function priority(): int
    {
        return 20;
    }

    /** @return array<int, string> */
    #[Override]
    public function dependsOn(): array
    {
        return ['core.never-registered'];
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
