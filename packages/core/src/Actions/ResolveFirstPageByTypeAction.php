<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Resolves the first published page of a given type for a site and language,
 * hydrating the site relation and resolved page-url site domain.
 *
 * @method static Page|null run(string $key, Site $site, ?Language $language = null, ?callable $modifyQueryUsing = null)
 */
final class ResolveFirstPageByTypeAction
{
    use AsFake;
    use AsObject;

    public function handle(
        string $key,
        Site $site,
        ?Language $language = null,
        ?callable $modifyQueryUsing = null,
    ): ?Page {
        if (! $language instanceof Language) {
            $site->loadMissing('language');
            $language = $site->language;
        }

        $query = Page::query()
            ->withWhereHas(
                'translation',
                fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language->id),
            )
            ->withWhereHas(
                'pageUrl',
                fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language->id),
            )
            ->whereHas('blueprint', fn (BuilderContract $query): BuilderContract => $query->where('key', $key)->enabled())
            ->where('site_id', $site->id)
            ->publishedDate();

        if ($modifyQueryUsing !== null) {
            $modifyQueryUsing($query);
        }

        $page = $query->first();

        if ($page instanceof Page) {
            $page->setRelation('site', $site);
            Page::setResolvedPageUrlSiteDomain($page, $site);
        }

        return $page;
    }
}
