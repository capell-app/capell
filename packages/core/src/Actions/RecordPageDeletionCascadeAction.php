<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Record live subtree members before the nested-set hook deletes descendants. */
final class RecordPageDeletionCascadeAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): void
    {
        // Serialise overlapping deletions before assigning their batch membership.
        $current = $page->newQuery()->withTrashed()->whereKey($page->getKey())->lockForUpdate()->first();
        if (! $current instanceof Page || $current->trashed()) {
            return;
        }

        $ids = $current->descendants()->getQuery()->lockForUpdate()->toBase()->pluck($page->getKeyName())->all();
        $ids[] = $page->getKey();
        PrunePageDeletionMembershipAction::run($page, array_map(intval(...), $ids), pageBatchesOnly: true);
        $batch = DeletionBatch::on($page->getConnectionName())->create([
            'root_type' => $page::class,
            'root_id' => $page->getKey(),
        ]);
        $recordedAt = now();
        $batch->records()->insert(array_map(fn (int|string $id): array => [
            'deletion_batch_id' => $batch->id,
            'model_type' => $page::class,
            'model_id' => (int) $id,
            'created_at' => $recordedAt,
            'updated_at' => $recordedAt,
        ], $ids));
    }
}
