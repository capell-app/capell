<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Actions\CollectPageRestoreCascadeIdsAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

/** Check every page restored by the observer and nested-set hooks before any write. */
final class CanRestorePageCascadeAction
{
    use AsObject;

    public function handle(Page $page): bool
    {
        $restoredIds = CollectPageRestoreCascadeIdsAction::run($page);
        if ($restoredIds === []) {
            return false;
        }

        $restoredPages = SiteAccess::current()->query($page::class)->onlyTrashed()->whereKey($restoredIds)->get();

        // A page omitted by site scoping is still restored by the model hooks.
        return $restoredPages->count() === count($restoredIds)
            && array_all($restoredPages->all(), fn (Model $restoredPage): bool => ! Gate::denies('restore', $restoredPage));
    }
}
