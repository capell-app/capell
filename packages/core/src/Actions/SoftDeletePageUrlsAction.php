<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Illuminate\Support\Facades\Date;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Soft-deletes a page's URLs without breaking the unique (site, language, url, deleted_at) index.
 *
 * The deleted_at column has one-second precision, so a URL that was trashed earlier in the same
 * second (a rollback removing several imported pages, or a page deleted straight after a
 * re-import) would collide. Each URL takes the first second not already used by a trashed row
 * for the same address.
 */
final class SoftDeletePageUrlsAction
{
    use AsFake;
    use AsObject;

    public function handle(Page $page): int
    {
        $deleted = 0;

        foreach ($page->pageUrls()->get() as $pageUrl) {
            $deletedAt = Date::now();

            while ($this->isTrashedAt($pageUrl, $deletedAt)) {
                $deletedAt = $deletedAt->addSecond();
            }

            $deleted += PageUrl::query()
                ->whereKey($pageUrl->getKey())
                ->update([
                    $pageUrl->getDeletedAtColumn() => $deletedAt,
                    $pageUrl->getUpdatedAtColumn() => Date::now(),
                ]);
        }

        return $deleted;
    }

    private function isTrashedAt(PageUrl $pageUrl, mixed $deletedAt): bool
    {
        return PageUrl::onlyTrashed()
            ->where('site_id', $pageUrl->site_id)
            ->where('language_id', $pageUrl->language_id)
            ->where('url', $pageUrl->url)
            ->where($pageUrl->getDeletedAtColumn(), $deletedAt)
            ->exists();
    }
}
