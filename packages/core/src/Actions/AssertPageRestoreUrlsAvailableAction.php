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
            ->enabled()->lockForUpdate()->get();
        $urls = $previousUrls->pluck('url')->unique()->all();
        if ($urls === []) {
            return;
        }

        $conflict = FindPageUrlRestorationConflictAction::run($previousUrls);
        if ($conflict instanceof PageUrl) {
            $ownerPage = $pages->firstWhere('id', $conflict->pageable_id);
            throw_unless($ownerPage instanceof Page, LogicException::class, 'A restore URL has no planned page owner.');
            throw new PageRestoreSlugConflictException($ownerPage, [$conflict->url => (int) $conflict->getAttribute('conflicting_page_id')]);
        }
    }
}
