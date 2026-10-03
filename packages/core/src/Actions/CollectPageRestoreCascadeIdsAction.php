<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Collect a conservative superset of the restore cascade, independently of author visibility.
 * Recursive ancestor restoration overwrites the nested-set hook's shared deletion
 * timestamp. Using each root's own timestamp can therefore over-collect descendants
 * that native hooks leave trashed; authorisation deliberately fails closed for them.
 * Locking also covers live candidates and requires the caller's transaction.
 */
final class CollectPageRestoreCascadeIdsAction
{
    use AsObject;

    /** @return list<int> */
    public function handle(Page $page, bool $lockForUpdate = false): array
    {
        $ancestorsQuery = $page->ancestors()->getQuery();
        if ($lockForUpdate) {
            $ancestorsQuery->withTrashed()->lockForUpdate();
        } else {
            $ancestorsQuery->onlyTrashed();
        }

        $ancestors = $ancestorsQuery->get()->filter(fn (Model $ancestor): bool => $ancestor instanceof Page && $ancestor->trashed());
        if ($lockForUpdate) {
            // Live ancestors and descendants can enter the cascade through a concurrent deletion.
            $root = $ancestors->sortBy($page->getLftName())->first() ?? $page;
            if (! $root instanceof Page) {
                return [];
            }

            $root->descendants()->getQuery()->withTrashed()->lockForUpdate()->get();
        }

        $roots = [$page, ...$ancestors->all()];
        $restoredIds = [];
        $restoreRanges = [];

        // Ancestor restoration can also restore siblings of the selected page.
        foreach ($roots as $root) {
            if (! $root instanceof Page) {
                return [];
            }

            $deletedAt = $root->deleted_at;
            if ($deletedAt === null) {
                return [];
            }

            $restoredIds[(int) $root->getKey()] = true;
            $restoreRanges[] = ['root' => $root, 'deleted_at' => $deletedAt->copy()->startOfSecond()];
        }

        // One query over the ranges avoids repeatedly reading overlapping subtrees.
        $descendantsQuery = $page->newQuery()->onlyTrashed()->where(function (Builder $query) use ($restoreRanges): void {
            foreach ($restoreRanges as $restoreRange) {
                $root = $restoreRange['root'];
                $deletedAt = $restoreRange['deleted_at'];
                $query->orWhere(fn (Builder $range): Builder => $range->whereDescendantOf($root)
                    ->where($root->getDeletedAtColumn(), '>=', $deletedAt));
            }
        });
        // Locking reads see current rows even when an ability check established an older transaction snapshot.
        if ($lockForUpdate) {
            $descendantsQuery->lockForUpdate();
        }

        $descendantIds = $descendantsQuery->pluck($page->getKeyName());
        foreach ($descendantIds as $descendantId) {
            $restoredIds[(int) $descendantId] = true;
        }

        return array_keys($restoredIds);
    }
}
