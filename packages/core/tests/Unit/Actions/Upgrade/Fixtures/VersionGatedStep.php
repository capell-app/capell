<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\Actions\Upgrade\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class VersionGatedStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.test.gated';
    }

    public function label(): string
    {
        return 'Gated';
    }

    #[Override]
    public function shouldRun(UpgradeContext $context): bool
    {
        return $context->compareVersions(
            $context->composerVersion('capell-app/capell') ?? '0.0.0',
            '5.0.0',
        ) < 0;
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
