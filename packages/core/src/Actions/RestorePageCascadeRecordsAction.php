<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\DeletionBatch;
use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsObject;

final class RestorePageCascadeRecordsAction
{
    use AsObject;

    /** @param list<int> $ids */
    public function handle(Page $page, array $ids): void
    {
        $page->newQuery()->onlyTrashed()->whereKey($ids)->restore();
        DeletionBatch::on($page->getConnectionName())
            ->where('root_type', $page::class)
            ->whereIn('root_id', $ids)
            ->open()
            ->update(['restored_at' => now()]);
    }
}
