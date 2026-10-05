<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\DeletionBatchRecord;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Collect the recorded restore cascade, independently of author visibility.
 * Untracked legacy trash restores only explicitly selected pages and ancestors:
 * a deletion timestamp cannot establish membership of a cascade.
 * Locking also covers live candidates and requires the caller's transaction.
 */
final class CollectPageRestoreCascadeIdsAction
{
    use AsFake;
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

            // Eloquent pluck hydrates cast primary keys; base queries keep this read scalar-only.
            $root->descendants()->getQuery()->withTrashed()->lockForUpdate()->toBase()->pluck($page->getKeyName());
        }

        $roots = [$page, ...$ancestors->all()];
        $restoredIds = [];
        $restoreRanges = [];

        // Ancestor restoration can also restore siblings of the selected page.
        foreach ($roots as $root) {
            if (! $root instanceof Page) {
                return [];
            }

            if (! $root->trashed()) {
                return [];
            }

            $restoredIds[(int) $root->getKey()] = true;
            $restoreRanges[] = $root;
        }

        // One query over the ranges avoids repeatedly reading overlapping subtrees.
        $descendantsQuery = $page->newQuery()->onlyTrashed()->where(function (Builder $query) use ($restoreRanges): void {
            foreach ($restoreRanges as $root) {
                $query->orWhere(function (Builder $range) use ($root): void {
                    $range->whereDescendantOf($root)
                        ->whereIn($root->getQualifiedKeyName(), $this->batchMemberIdsQuery($root));
                });
            }
        });
        // Locking reads see current rows even when an ability check established an older transaction snapshot.
        if ($lockForUpdate) {
            $descendantsQuery->lockForUpdate();
        }

        $descendantIds = $descendantsQuery->toBase()->pluck($page->getKeyName());
        foreach ($descendantIds as $descendantId) {
            $restoredIds[(int) $descendantId] = true;
        }

        return array_keys($restoredIds);
    }

    /**
     * Collect exclusions without author visibility; the caller must hold the restore transaction.
     *
     * @param  list<int>  $restoredIds
     * @return list<int>
     */
    public function collectExcludedDescendantIds(Page $root, array $restoredIds): array
    {
        $ids = $root->newQuery()->onlyTrashed()
            ->whereDescendantOf($root)->whereNotIn($root->getQualifiedKeyName(), $restoredIds)
            ->lockForUpdate()->toBase()->pluck($root->getKeyName())->all();

        return array_values(array_map(intval(...), $ids));
    }

    /** @return Builder<DeletionBatchRecord> */
    private function batchMemberIdsQuery(Page $root): Builder
    {
        $records = DeletionBatchRecord::on($root->getConnectionName())
            ->where('model_type', $root::class)
            ->whereHas('batch', fn (Builder $batch): Builder => $batch->where('root_type', $root::class)->whereNull('restored_at'));
        $rootBatch = (clone $records)->select('deletion_batch_id')
            ->where('model_id', $root->getKey())->latest('id')->limit(1);
        // A later independent deletion supersedes membership in an older parent cascade.
        $latestRecords = (clone $records)->selectRaw('MAX(id)')->groupBy('model_id');

        return (clone $records)->select('model_id')
            ->where('deletion_batch_id', $rootBatch)
            ->whereIn('id', $latestRecords)
            ->whereHas('batch', fn (Builder $batch): Builder => $batch->whereNull('restored_at'));
    }
}
