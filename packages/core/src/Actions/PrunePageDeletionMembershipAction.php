<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\DeletionBatchRecord;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PrunePageDeletionMembershipAction
{
    use AsFake;
    use AsObject;

    /** @param list<int> $ids */
    public function handle(Page $page, array $ids, bool $pageBatchesOnly = false): void
    {
        $records = DeletionBatchRecord::on($page->getConnectionName())
            ->where('model_type', $page::class)
            ->whereIn('model_id', $ids);
        if ($pageBatchesOnly) {
            $records->whereHas('batch', fn (Builder $batch): Builder => $batch->where('root_type', $page::class));
        }

        $batchIds = (clone $records)->lockForUpdate()->pluck('deletion_batch_id')->unique()->all();
        $records->delete();

        // Only inspect touched batches: another transaction may still be recording its members.
        DeletionBatch::on($page->getConnectionName())
            ->whereKey($batchIds)
            ->doesntHave('records')
            ->delete();
    }
}
