<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\HeaderNavigation;

use Capell\Admin\Data\HeaderNavigation\HeaderNavigationSearchPathData;
use Capell\Admin\Data\HeaderNavigation\HeaderNavigationSearchResultsData;
use Capell\Admin\Data\HeaderNavigation\HeaderNavigationSiteData;
use Capell\Admin\Support\HeaderNavigation\HeaderNavigationAccessResolver;
use Capell\Admin\Support\HeaderNavigation\HeaderNavigationPageNodeBuilder;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SearchHeaderNavigationPagesAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly HeaderNavigationAccessResolver $accessResolver,
        private readonly HeaderNavigationPageNodeBuilder $nodeBuilder,
    ) {}

    public function handle(
        ?Authenticatable $actor,
        string $search,
        int $page = 1,
        int $perPage = 10,
    ): HeaderNavigationSearchResultsData {
        $search = trim($search);
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));

        if (! $actor instanceof Authenticatable || mb_strlen($search) < 2) {
            return new HeaderNavigationSearchResultsData([], $page, $perPage, false);
        }

        $siteIds = $this->accessResolver->visibleSiteIds($actor);

        if ($siteIds === []) {
            return new HeaderNavigationSearchResultsData([], $page, $perPage, false);
        }

        /** @var Builder<Page> $query */
        $query = Page::query()
            ->select('pages.id')
            ->distinct()
            ->orderBy('pages.site_id')
            ->orderBy('pages._lft');

        $this->accessResolver->constrainPageQueryForSites($query, $actor, $siteIds);
        $this->applySearchConstraint($query, $search);

        $ids = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage + 1)
            ->pluck('id')
            ->map(fn (int|string $pageId): int => (int) $pageId)
            ->values();

        $hasMore = $ids->count() > $perPage;
        $pageIds = $ids->take($perPage)->all();

        if ($pageIds === []) {
            return new HeaderNavigationSearchResultsData([], $page, $perPage, false);
        }

        /** @var Collection<int, Page> $matches */
        $matches = Page::query()
            ->with(['site.defaultDomain', 'blueprint.roleRestrictions', 'pageUrl.siteDomain'])
            ->whereIn('id', $pageIds)
            ->get()
            ->sortBy(fn (Page $match): int => array_search((int) $match->getKey(), $pageIds, true) ?: 0)
            ->values();

        $paths = array_values($matches
            ->map(fn (Page $match): ?HeaderNavigationSearchPathData => $this->pathForMatch($actor, $match))
            ->filter()
            ->unique(fn (HeaderNavigationSearchPathData $path): string => $path->toRecord()['key'])
            ->values()
            ->all());

        return new HeaderNavigationSearchResultsData($paths, $page, $perPage, $hasMore);
    }

    /**
     * @param  Builder<Page>  $query
     */
    private function applySearchConstraint(Builder $query, string $search): void
    {
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

        $query->where(function (Builder $query) use ($like): void {
            $query
                ->where('pages.name', 'like', $like)
                ->orWhereHas(
                    'translations',
                    fn (BuilderContract $query): BuilderContract => $query
                        ->where('title', 'like', $like)
                        ->orWhere('meta->slug', 'like', $like),
                )
                ->orWhereHas(
                    'pageUrls',
                    fn (BuilderContract $query): BuilderContract => $query->where('url', 'like', $like),
                );
        });
    }

    private function pathForMatch(Authenticatable $actor, Page $match): ?HeaderNavigationSearchPathData
    {
        if (! $this->accessResolver->canViewPage($actor, $match) || $this->accessResolver->editUrlFor($match) === null) {
            return null;
        }

        $ancestors = $match->ancestors()->get();
        $ancestors->load(['site', 'blueprint.roleRestrictions', 'pageUrl.siteDomain']);

        /** @var Collection<int, Page> $pathPages */
        $pathPages = $ancestors->push($match);

        if ($pathPages->contains(fn (Page $page): bool => ! $this->accessResolver->canViewPage($actor, $page) || $this->accessResolver->editUrlFor($page) === null)) {
            return null;
        }

        $site = $match->site;

        if (! $site instanceof Site) {
            return null;
        }

        return new HeaderNavigationSearchPathData(
            site: new HeaderNavigationSiteData(
                id: (int) $site->getKey(),
                name: (string) $site->name,
                editUrl: $this->accessResolver->siteEditUrlFor($site),
                publicUrl: $site->defaultDomain?->full_url,
            ),
            nodes: $this->nodeBuilder->build($actor, $pathPages),
            matchId: (int) $match->getKey(),
        );
    }
}
