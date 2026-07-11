<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Activity;

use Capell\Admin\Data\Activity\ActivityRevertResultData;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;

interface ActivityRevertHandler
{
    public const string TAG = 'capell.admin.activity-revert-handler';

    public function supports(ActivityRevertSelectionData $selection): bool;

    public function priority(): int;

    public function revert(ActivityRevertSelectionData $selection): ActivityRevertResultData;
}
