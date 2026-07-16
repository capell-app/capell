<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use BackedEnum;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Scopes\LanguagesOrderScope;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Data\PageListingSpec;
use Capell\Frontend\Support\Cache\PageHydrator;
use Capell\Frontend\Support\Cache\PageListingCache;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** @phpstan-type PageModel Model&Pageable<Model> */
final class ListPagesAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Pageable<Model>|null  $page
     * @param  class-string<PageModel>|non-empty-string|null  $morphModel
     * @param  Closure(Builder<Model>): void|null  $modifyQuery
     * @return Collection<int, PageModel>|LengthAwarePaginator<int, PageModel>
     */
    public function handle(
        Language $language,
        ?Site $site = null,
        ?Pageable $page = null,
        ?string $type = null,
        ?int $limit = null,
        ?int $paginationPage = null,
        ?PageOrderEnum $ordering = null,
        ?string $pageType = null,
        null|string|BackedEnum $pageGroup = null,
        ?string $typeKey = null,
        bool $optionalLanguage = false,
        bool $withChildrenCount = false,
        bool $withChildren = false,
        bool $withPagination = false,
        bool $withParent = false,
        bool $withDate = false,
        bool $onlyListableTypes = true,
        string $paginationKey = 'pages',
        string $cacheKeyPrepend = '',
        ?string $morphModel = null,
        bool $useCache = true,
        ?Closure $modifyQuery = null,
    ): Collection|LengthAwarePaginator {
        if ($withPagination && ($limit === null || $limit === 0)) {
            $limit = config('capell-frontend.pagination_limit', 10);
        }

        if ($withPagination && $limit === null) {
            $limit = 10;
        }

        $morphedModel = filled($morphModel)
            ? (Relation::getMorphedModel($morphModel) ?? $morphModel)
            : Page::class;

        if (! is_subclass_of($morphedModel, Model::class)
            || ! is_subclass_of($morphedModel, Pageable::class)
        ) {
            $model = Page::class;
        } else {
            /** @var class-string<PageModel> $model */
            $model = $morphedModel;
        }

        if (! $ordering instanceof PageOrderEnum && $model !== Page::class) {
            $ordering = $model::defaultOrdering();
        }

        if ($optionalLanguage) {
            $cacheKeyPrepend = 'optionalLanguage-' . $cacheKeyPrepend;
        }

        $spec = PageListingSpec::fromGetPages(
            language: $language,
            site: $site,
            page: $page,
            type: $type,
            limit: $limit,
            ordering: $ordering,
            pageType: $pageType,
            pageGroup: $pageGroup,
            typeKey: $typeKey,
            optionalLanguage: $optionalLanguage,
            onlyListableTypes: $onlyListableTypes,
            morphModel: $morphModel,
            cacheKeySuffix: $cacheKeyPrepend,
        );

        $idLoader = fn (): array => $this->buildIdQuery(
            model: $model,
            language: $language,
            site: $site,
            page: $page,
            type: $type,
            ordering: $ordering,
            pageType: $pageType,
            pageGroup: $pageGroup instanceof BackedEnum ? $pageGroup->value : $pageGroup,
            typeKey: $typeKey,
            optionalLanguage: $optionalLanguage,
            onlyListableTypes: $onlyListableTypes,
            modifyQuery: $modifyQuery,
        )->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $listingCache = resolve(PageListingCache::class);
        $allIds = $useCache ? $listingCache->getIds($spec, $idLoader) : $idLoader();

        if (! $withPagination && $limit !== null) {
            $allIds = array_slice($allIds, 0, $limit);
        }

        if ($withPagination) {
            return $this->paginate(
                ids: $allIds,
                model: $model,
                site: $site,
                language: $language,
                limit: (int) $limit,
                paginationPage: $paginationPage,
                paginationKey: $paginationKey,
                withParent: $withParent,
                withChildren: $withChildren,
                withChildrenCount: $withChildrenCount,
                withDate: $withDate,
                useCache: $useCache,
            );
        }

        $pages = resolve(PageHydrator::class)->hydrate(
            ids: $allIds,
            morphType: $model,
            site: $site,
            language: $language,
            withParent: $withParent,
            withChildren: $withChildren,
            withChildrenCount: $withChildrenCount,
            withDate: $withDate,
        );

        if ($useCache) {
            $this->trackModelOrCollection($pages);
        }

        return $pages;
    }

    /**
     * @param  class-string<PageModel>  $model
     * @param  array<int, int>  $ids
     * @return LengthAwarePaginator<int, PageModel>
     */
    private function paginate(
        array $ids,
        string $model,
        ?Site $site,
        Language $language,
        int $limit,
        ?int $paginationPage,
        string $paginationKey,
        bool $withParent,
        bool $withChildren,
        bool $withChildrenCount,
        bool $withDate,
        bool $useCache,
    ): LengthAwarePaginator {
        $totalCount = count($ids);
        $currentPage = max(1, $paginationPage ?? 1);
        $offset = ($currentPage - 1) * $limit;
        $pageIds = array_slice($ids, $offset, $limit);

        $items = resolve(PageHydrator::class)->hydrate(
            ids: $pageIds,
            morphType: $model,
            site: $site,
            language: $language,
            withParent: $withParent,
            withChildren: $withChildren,
            withChildrenCount: $withChildrenCount,
            withDate: $withDate,
        );

        $paginator = new LengthAwarePaginator(
            items: $items,
            total: $totalCount,
            perPage: $limit,
            currentPage: $currentPage,
            options: ['pageName' => $paginationKey],
        );

        if ($useCache) {
            $this->trackModelOrCollection($paginator->getCollection());
        }

        return $paginator;
    }

    /**
     * @param  class-string<PageModel>  $model
     * @param  Pageable<Model>|null  $page
     * @param  Closure(Builder<Model>): void|null  $modifyQuery
     * @return Builder<Model>
     */
    private function buildIdQuery(
        string $model,
        Language $language,
        ?Site $site,
        ?Pageable $page,
        ?string $type,
        ?PageOrderEnum $ordering,
        ?string $pageType,
        ?string $pageGroup,
        ?string $typeKey,
        bool $optionalLanguage,
        bool $onlyListableTypes,
        ?Closure $modifyQuery,
    ): Builder {
        $languageIds = [$language->id];

        $relationOrQuery = match ($type) {
            'siblings' => $page?->siblings() ?? $model::query(),
            'children' => $page?->children() ?? $model::query(),
            default => $model::query(),
        };
        /** @var Builder<Model> $query */
        $query = $relationOrQuery instanceof Relation ? $relationOrQuery->getQuery() : $relationOrQuery;

        $query->publishedDate();

        $query
            ->select($query->getModel()->getTable() . '.id')
            ->withWhereHas('translation', fn (BuilderContract $translationQuery): BuilderContract => $translationQuery->when(
                $optionalLanguage,
                fn (BuilderContract $innerQuery): BuilderContract => LanguagesOrderScope::applyTo($innerQuery, $languageIds),
                fn (BuilderContract $innerQuery) => $innerQuery->where('language_id', $language->id),
            ))
            ->withWhereHas(
                'pageUrl',
                fn (BuilderContract $urlQuery) => $urlQuery->whereNull('type')
                    ->enabled()
                    ->when(
                        $optionalLanguage,
                        fn (BuilderContract $innerQuery): BuilderContract => LanguagesOrderScope::applyTo($innerQuery, $languageIds),
                        fn (BuilderContract $innerQuery) => $innerQuery->where('language_id', $language->id),
                    ),
            )
            ->withWhereHas(
                'blueprint',
                fn (BuilderContract $typeQuery) => $typeQuery
                    ->when($pageType, fn (BuilderContract $innerQuery) => $innerQuery->where('type', $pageType))
                    ->when($pageGroup !== null, fn (BuilderContract $innerQuery) => $innerQuery->where('group', $pageGroup))
                    ->when($typeKey, fn (BuilderContract $innerQuery) => $innerQuery->where('key', $typeKey))
                    ->enabled()
                    ->accessible()
                    ->when($onlyListableTypes, fn (BuilderContract $innerQuery) => $innerQuery->listable()),
            )
            ->tap(
                fn (BuilderContract $orderQuery) => match ($ordering) {
                    PageOrderEnum::Alphabetical => $this->callQueryScope($orderQuery, 'alphabetical', $language),
                    PageOrderEnum::Latest, null => $this->callQueryScope($orderQuery, 'publishedLatest'),
                    PageOrderEnum::Oldest => $this->callQueryScope($orderQuery, 'publishedOldest'),
                    PageOrderEnum::Default => $this->callQueryScope($orderQuery, 'ordered'),
                },
            );

        if ($site instanceof Site) {
            $query->where($query->getModel()->getTable() . '.site_id', $site->id);
        }

        if ($modifyQuery instanceof Closure) {
            $modifyQuery($query);
        }

        return $query;
    }

    private function callQueryScope(BuilderContract $query, string $scope, mixed ...$arguments): void
    {
        $callable = [$query, $scope];

        if (! is_callable($callable)) {
            return;
        }

        $callable(...$arguments);
    }

    /**
     * @param  Collection<int, PageModel>|SupportCollection<int, PageModel>|PageModel  $modelOrCollection
     */
    private function trackModelOrCollection(Collection|SupportCollection|Model $modelOrCollection): void
    {
        if ($modelOrCollection instanceof Collection) {
            $modelOrCollection->each(fn (Model $model) => resolve(RenderedModelTracker::class)->track($model));

            return;
        }

        resolve(RenderedModelTracker::class)->track($modelOrCollection);
    }
}
