<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Exceptions\PageRestoreSlugConflictException;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class AssertPageRestoreUrlsAvailableAction
{
    use AsFake;
    use AsObject;

    /** @param Collection<int, Page> $pages */
    public function handle(Collection $pages): void
    {
        $page = $pages->first();
        if (! $page instanceof Page) {
            return;
        }

        $previousUrls = PageUrl::on($page->getConnectionName())->onlyTrashed()
            ->where('pageable_type', $page->getMorphClass())
            ->whereIn('pageable_id', $pages->modelKeys())
            ->lockForUpdate()->get();
        $urls = $previousUrls->pluck('url')->filter()->unique()->all();
        if ($urls === []) {
            return;
        }

        $liveUrls = PageUrl::on($page->getConnectionName())->whereIn('url', $urls)->lockForUpdate()->get();
        $owners = $liveUrls->concat($previousUrls)->groupBy('url');
        $pagesById = $pages->keyBy('id');
        foreach ($previousUrls as $previousUrl) {
            if ($previousUrl->url === '') {
                continue;
            }

            $collisions = [];
            foreach ($owners->get($previousUrl->url, collect()) as $owner) {
                if ($owner->pageable_type !== $previousUrl->pageable_type || $owner->pageable_id !== $previousUrl->pageable_id) {
                    $collisions[$previousUrl->url] = (int) $owner->pageable_id;
                }
            }

            if ($collisions !== []) {
                $ownerPage = $pagesById->get($previousUrl->pageable_id);
                throw_unless($ownerPage instanceof Page, LogicException::class, 'A restore URL has no planned page owner.');
                throw new PageRestoreSlugConflictException($ownerPage, $collisions);
            }
        }
    }
}
