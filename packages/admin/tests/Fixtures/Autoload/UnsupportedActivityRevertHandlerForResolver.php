<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Data\Activity\ActivityRevertResultData;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;

final class UnsupportedActivityRevertHandlerForResolver implements ActivityRevertHandler
{
    public function priority(): int
    {
        return 0;
    }

    public function supports(ActivityRevertSelectionData $selection): bool
    {
        return false;
    }

    public function revert(ActivityRevertSelectionData $selection): ActivityRevertResultData
    {
        return ActivityRevertResultData::failed('capell-admin::activity.revert_failed');
    }
}
