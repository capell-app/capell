<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Data\Activity\ActivityRevertResultData;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;

final class TestActivityRevertHandlerForRegistrar implements ActivityRevertHandler
{
    public function supports(ActivityRevertSelectionData $selection): bool
    {
        return true;
    }

    public function priority(): int
    {
        return 0;
    }

    public function revert(ActivityRevertSelectionData $selection): ActivityRevertResultData
    {
        return ActivityRevertResultData::success('capell-admin::activity.reverted');
    }
}
