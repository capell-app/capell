<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Publishing;

use Capell\Tests\Fixtures\Models\User;

final class SharedStatusRecordPolicy
{
    public const string VIEW_PERMISSION = 'view-shared-status-record';

    public const string UPDATE_PERMISSION = 'update-shared-status-record';

    public function view(User $user, StatusOnlyRecord $record): bool
    {
        if ($user->isGlobalAdmin()) {
            return true;
        }

        $siteIds = $user->getAssignedSiteIds();
        $siteId = $record->getAttribute('site_id');

        return $user->checkPermissionTo(self::VIEW_PERMISSION)
            && $siteIds->isNotEmpty()
            && ($siteId === null || $siteIds->contains((int) $siteId));
    }

    public function update(User $user, StatusOnlyRecord $record): bool
    {
        return $this->view($user, $record)
            && ($user->isGlobalAdmin() || $user->checkPermissionTo(self::UPDATE_PERMISSION));
    }
}
