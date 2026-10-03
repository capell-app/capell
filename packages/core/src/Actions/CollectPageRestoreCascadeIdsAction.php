<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Page;
use Lorisleiva\Actions\Concerns\AsObject;

/** Identify the complete restore cascade, independently of author visibility. */
final class CollectPageRestoreCascadeIdsAction
{
    use AsObject;

    /** @return list<int> */
    public function handle(Page $page): array
    {
        $ancestors = $page->ancestors()->getQuery()->onlyTrashed()->get();
        $restoredIds = [];

        // Ancestor restoration can also restore siblings of the selected page.
        foreach ([$page, ...$ancestors->all()] as $root) {
            if (! $root instanceof Page) {
                return [];
            }

            $deletedAt = $root->deleted_at;
            if ($deletedAt === null) {
                return [];
            }

            $restoredIds[(int) $root->getKey()] = true;
            $descendantIds = $root->descendants()->getQuery()->onlyTrashed()
                ->where($root->getDeletedAtColumn(), '>=', $deletedAt->copy()->startOfSecond())
                ->pluck($root->getKeyName());
            foreach ($descendantIds as $descendantId) {
                $restoredIds[(int) $descendantId] = true;
            }
        }

        return array_keys($restoredIds);
    }
}
