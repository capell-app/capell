<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Support\Pages\PagePublishSentinel;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Makes a collection of Page records immediately visible by setting their
 * `visible_from` timestamp to now (if not already set). Pages for which the
 * actor lacks the `update` permission are silently skipped; the returned
 * count reflects only the records that were actually updated.
 */
class BulkPublishPagesAction
{
    use AsObject;

    /**
     * @param  Collection<int, Page&Pageable>  $pages
     * @return array{
     *     published: int,
     *     skipped: int,
     *     skipped_pages: list<array{id: int, name: string, reason: string}>
     * }
     */
    public function handle(Collection $pages, User $actor): array
    {
        $publishedCount = 0;
        $skippedCount = 0;
        $skippedPages = [];

        foreach ($pages as $page) {
            $response = Gate::forUser($actor)->inspect('update', $page);

            if (! $response->allowed()) {
                $skippedCount++;
                $skippedPages[] = [
                    'id' => (int) $page->getKey(),
                    'name' => (string) $page->getAttribute('name'),
                    'reason' => 'unauthorized',
                ];

                continue;
            }

            // Set visible_from to now when the page has no publish date yet,
            // making it immediately visible on the public frontend. Also clear
            // far-future draft sentinels — that's exactly the publish action.
            if ($page->visible_from === null
                || ($page->isPending() && PagePublishSentinel::isDraftValue($page->visible_from))) {
                $page->visible_from = CarbonImmutable::now();
                $page->save();
            }

            $publishedCount++;
        }

        return [
            'published' => $publishedCount,
            'skipped' => $skippedCount,
            'skipped_pages' => $skippedPages,
        ];
    }
}
