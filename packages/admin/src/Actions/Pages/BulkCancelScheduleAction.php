<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Cancels future publish/unpublish schedules on a collection of pages.
 * Only future timestamps are cleared — past or currently-active visibility
 * windows are not affected. Pages the actor cannot update are silently skipped.
 */
class BulkCancelScheduleAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Collection<int, Page&Pageable>  $pages
     * @return array{cancelled: int, skipped: int}
     */
    public function handle(Collection $pages, User $actor): array
    {
        $cancelledCount = 0;
        $skippedCount = 0;

        foreach ($pages as $page) {
            $response = Gate::forUser($actor)->inspect('update', $page);

            if (! $response->allowed()) {
                $skippedCount++;

                continue;
            }

            $hasFutureFrom = $page->visible_from !== null && $page->visible_from->isFuture();
            $hasFutureUntil = $page->visible_until !== null && $page->visible_until->isFuture();

            if (! $hasFutureFrom && ! $hasFutureUntil) {
                $skippedCount++;

                continue;
            }

            if ($hasFutureFrom) {
                $page->visible_from = null;
            }

            if ($hasFutureUntil) {
                $page->visible_until = null;
            }

            $page->save();
            $cancelledCount++;
        }

        return [
            'cancelled' => $cancelledCount,
            'skipped' => $skippedCount,
        ];
    }
}
