<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Autoload;

use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Data\Activity\ActivityRevertResultData;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Core\Models\Language;

final class PermissiveActivityRevertHandlerForTest implements ActivityRevertHandler
{
    public function priority(): int
    {
        return 100;
    }

    public function supports(ActivityRevertSelectionData $selection): bool
    {
        return true;
    }

    public function revert(ActivityRevertSelectionData $selection): ActivityRevertResultData
    {
        $language = Language::query()->findOrFail($selection->subjectId);
        $language->update(['name' => 'Permissive handler changed this']);

        return ActivityRevertResultData::success('capell-admin::activity.reverted');
    }
}
