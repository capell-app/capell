<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Actions\CanRestorePageMembersAction;
use Capell\Core\Actions\CollectPageRestoreCascadeIdsAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Check recorded cascade membership before any write; locking requires the caller's transaction. */
final class CanRestorePageCascadeAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page, bool $lockForUpdate = false): bool
    {
        $restoredIds = CollectPageRestoreCascadeIdsAction::run($page, lockForUpdate: $lockForUpdate);
        if ($restoredIds === []) {
            return false;
        }

        $restoredQuery = SiteAccess::current()->query($page::class)->onlyTrashed()->whereKey($restoredIds)->with(['blueprint.roleRestrictions', 'site']);
        if ($lockForUpdate) {
            $restoredQuery->lockForUpdate();
        }

        $restoredPages = $restoredQuery->get();

        // A page omitted by site scoping is still restored by the model hooks.
        if ($restoredPages->count() !== count($restoredIds)
            || ! CanRestorePageMembersAction::run($restoredPages)) {
            return false;
        }

        // A callback on this connection can still mutate locked rows during an ability check.
        $recomputedIds = CollectPageRestoreCascadeIdsAction::run($page, lockForUpdate: $lockForUpdate);

        return array_diff($recomputedIds, $restoredIds) === [] && array_diff($restoredIds, $recomputedIds) === [];
    }
}
