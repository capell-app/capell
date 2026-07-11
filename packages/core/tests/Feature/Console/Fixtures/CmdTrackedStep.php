<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Console\Fixtures;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Support\Upgrade\AbstractUpgradeStep;
use Override;

class CmdTrackedStep extends AbstractUpgradeStep
{
    public static int $runs = 0;

    public function id(): string
    {
        return 'core.cmd-tracked';
    }

    public function label(): string
    {
        return 'Cmd tracked';
    }

    #[Override]
    public function priority(): int
    {
        return 10;
    }

    public function run(UpgradeContext $context): bool
    {
        self::$runs++;

        return true;
    }
}
