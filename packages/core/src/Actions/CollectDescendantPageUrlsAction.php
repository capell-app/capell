<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Snapshot the canonical URLs of every descendant of a page, keyed by
 * descendant page id then language id. Must run before a slug or parent
 * change is saved: the save rewrites descendant URLs in place, so the
 * old URLs are only readable ahead of it.
 */
class CollectDescendantPageUrlsAction
{
    use AsFake;
    use AsObject;

    /**
     * @template TDeclaringModel of Model
     *
     * @param  Pageable<TDeclaringModel>  $page
     * @return array<int, array<int, string>>
     */
    public function handle(Pageable $page): array
    {
        $snapshots = [];

        // Query by key so the tree bounds are read fresh from the database;
        // the instance's nested-set bounds can be stale after other inserts.
        $descendants = $page->newQuery()->whereDescendantOf($page->getKey())->get();
        $descendants->load('pageUrls');

        foreach ($descendants as $descendant) {
            if (! $descendant instanceof Pageable) {
                continue;
            }

            foreach ($descendant->pageUrls as $pageUrl) {
                if (! $pageUrl instanceof PageUrl) {
                    continue;
                }

                if ($pageUrl->type === UrlTypeEnum::Redirect) {
                    continue;
                }

                if ($pageUrl->language_id === null) {
                    continue;
                }

                if (! is_string($pageUrl->url)) {
                    continue;
                }

                $snapshots[(int) $descendant->getKey()][(int) $pageUrl->language_id] = $pageUrl->url;
            }
        }

        return $snapshots;
    }
}
