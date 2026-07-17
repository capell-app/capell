<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Reverts a collection of Page records back to draft state by setting their
 * `visible_from` to the far-future sentinel (now()->addYears(100)).
 *
 * Returns structured per-page outcome data so the calling bulk action can
 * show editors *why* certain pages were skipped — not just a count.
 */
class BulkRevertPagesToDraftAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Page&Pageable>  $pages
     * @return array{
     *     reverted: int,
     *     skipped: int,
     *     skipped_pages: list<array{id: int, name: string, reason: string}>
     * }
     */
    public function handle(Collection $pages, User $actor): array
    {
        $reverted = 0;
        $skipped = 0;
        $skippedPages = [];
        $sentinel = PagePublishSentinel::draftValue();

        foreach ($pages as $page) {
            $reason = $this->skipReason($page, $actor);

            if ($reason !== null) {
                $skipped++;
                $skippedPages[] = [
                    'id' => (int) $page->getKey(),
                    'name' => (string) $page->getAttribute('name'),
                    'reason' => $reason,
                ];

                continue;
            }

            $page->visible_from = $sentinel;
            $page->save();
            $reverted++;
        }

        return [
            'reverted' => $reverted,
            'skipped' => $skipped,
            'skipped_pages' => $skippedPages,
        ];
    }

    private function skipReason(Page $page, User $actor): ?string
    {
        if ($page->trashed()) {
            return 'trashed';
        }

        if (! Gate::forUser($actor)->allows('update', $page)) {
            return 'unauthorized';
        }

        if (PagePublishSentinel::isDraftValue($page->visible_from)) {
            return 'already_draft';
        }

        return null;
    }
}
