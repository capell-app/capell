<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Console\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

final class ReportOnlyMutatingStep extends AbstractUpgradeStep
{
    public static int $shouldRunCalls = 0;

    public function id(): string
    {
        return 'core.test.report-only-mutating';
    }

    public function label(): string
    {
        return 'Report-only mutating step';
    }

    #[Override]
    public function shouldRun(UpgradeContext $context): bool
    {
        self::$shouldRunCalls++;

        return true;
    }

    public function run(UpgradeContext $context): bool
    {
        return true;
    }
}
