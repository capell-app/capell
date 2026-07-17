<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Aimeos\Nestedset\QueryBuilder;
use BackedEnum;
use Capell\Core\Actions\ResolvePublicPageableMorphTypesAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ListPagesAction;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Cache\PageModelCache;
use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @phpstan-type PageArray array<string, mixed>
 */
class PageLoader
{
    /**
     * @param  Pageable<Model>  $page
     * @return Collection<int, Page>
     */
    public static function getCanonicalPages(Pageable $page, Language $language): Collection
    {
        $key = CacheEnum::pageCanonicals($page->id, $language->id);

        $fromCache = true;

        $pages = CapellCore::rememberCache($key, function () use ($page, $language, &$fromCache): Collection {
            $fromCache = false;

            /** @var Builder<Page> $builder */
            $builder = $page->canonicalPages()->getQuery();

            return $builder
                ->withWhereHas(
                    'translation',
                    fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language->id),
                )
                ->withWhereHas(
                    'pageUrl',
                    fn (BuilderContract|Relation $query): BuilderContract|Relation => $query
                        ->whereNull('type')
                        ->enabled()
                        ->where('language_id', $language->id),
                )
                ->publishedDate()
                ->get();
        });

        $pages->each(function (Pageable $page) use ($language): void {
            $page->pageUrl?->setRelation('language', $language);
            $page->translation?->setRelation('language', $language);
        });

        if ($fromCache) {
            self::trackModelOrCollection($pages);
        }

        return $pages;
    }

    public static function getErrorPage(Site $site, Language $language, string $type = 'error', bool $withEvents = true): ?Page
    {
        return self::getSystemPage($site, $language, $type, $withEvents);
    }

    public static function getSystemPage(Site $site, Language $language, string $type, bool $withEvents = true): ?Page
    {
        $key = CacheEnum::systemPage($type, $site->id, $language->id);

        $fromCache = true;

        $page = CapellCore::rememberCache($key, function () use ($site, $language, $type, $withEvents, &$fromCache): ?Page {
            $fromCache = false;

            $callback = function () use ($type, $language, $site): ?Page {
                /** @var class-string<Page> $model */
                $model = Page::class;

                $builder = $model::query();

                $builder->with([
                    'layout',
                    'pageUrls.siteDomain',
                ])
                    ->withWhereHas(
                        'blueprint',
                        function (BuilderContract $query) use ($type): void {
                            /** @var Builder<Blueprint> $query */
                            $query->where('key', $type)->enabled();
                        },
                    )
                    ->withWhereHas(
                        'pageUrl',
                        function (BuilderContract $query) use ($language): void {
                            $query->where('language_id', $language->id)
                                ->whereNull('type')
                                ->enabled();
                        },
                    )
                    ->withWhereHas(
                        'translation',
                        fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language->id),
                    )
                    ->where('site_id', $site->id)
                    ->publishedDate();

                $record = $builder->first();

                return $record instanceof Page ? $record : null;
            };

            if ($withEvents) {
                return $callback();
            }

            return PageUrl::withoutEvents($callback);
        });

        if ($page === null || $page->translation === null || $page->pageUrl === null) {
            return null;
        }

        self::setPageRelations($page, $site, $language);
        $page->pageUrls->each(fn (PageUrl $url) => $url->setRelation('language', $language));
        if ($fromCache) {
            self::trackModelOrCollection($page);
        }

        return $page;
    }

    public static function getSiteHomePage(Site $site, Language $language): ?Page
    {
        $key = CacheEnum::homePage($site->id, $language->id);

        $fromCache = true;

        $page = CapellCore::rememberCache($key, function () use ($site, $language, &$fromCache): ?Page {
            $fromCache = false;

            /** @var class-string<Page> $model */
            $model = Page::class;

            return $model::getSiteHomePage(site: $site, language: $language);
        });

        if ($page === null) {
            return null;
        }

        self::setPageRelations($page, $site, $language);
        if ($fromCache) {
            self::trackModelOrCollection($page);
        }

        return $page;
    }

    /**
     * @param  Pageable<Model>  $page
     * @return Pageable<Model>|null
     */
    public static function getNextPage(Pageable $page, Site $site, Language $language): ?Pageable
    {
        return self::getAdjacentPage($page, $site, $language, 'next');
    }

    /**
     * @param  Pageable<Model>  $page
     * @return Pageable<Model>|null
     */
    public static function getPreviousPage(Pageable $page, Site $site, Language $language): ?Pageable
    {
        return self::getAdjacentPage($page, $site, $language, 'previous');
    }

    /**
     * @param  Pageable<Model>  $page
     * @return Collection<int, Page>|null
     */
    public static function getPageAncestors(Pageable $page, Language $language, Site $site): ?Collection
    {
        if ($page->hasPageHierarchy() === false) {
            return null;
        }

        /** @var QueryBuilder<Page> $builder */
        $builder = $page->ancestors()->getQuery();

        $pages = $builder->withWhereHas(
            'translation',
            fn (BuilderContract $query): BuilderContract => $query->where('language_id', $language->id),
        )
            ->withWhereHas(
                'pageUrl',
                fn (BuilderContract $query): BuilderContract => $query->whereNull('type')
                    ->enabled()
                    ->where('language_id', $language->id),
            )
            ->withWhereHas('blueprint', fn (BuilderContract $query): BuilderContract => $query->enabled()->accessible())
            ->where('site_id', $site->id)
            ->publishedDate()
            ->orderBy('_lft', 'asc')
            ->get();

        if ($pages->isEmpty()) {
            return null;
        }

        $pages->each(fn (Pageable $page) => self::setPageRelations($page, $site, $language));

        return $pages;
    }

    /**
     * Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['accessible' => false]))
     *
     * @param  Pageable<Model>|null  $page
     * @param  class-string<Pageable<Model>>|null  $morphModel
     * @param  Closure(Builder<Model>): void|null  $modifyQuery
     * @return Collection<int, Model&Pageable<Model>>|LengthAwarePaginator<int, Model&Pageable<Model>>
     */
    public static function getPages(
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
        // Kept for backwards compatibility — image is always loaded by PageModelCache's canonical relations.
        bool $withImage = false,
        bool $withPagination = false,
        bool $withParent = false,
        bool $withDate = false,
        bool $onlyListableTypes = true,
        string $paginationKey = 'pages',
        string $cacheKeyPrepend = '',
        /** @param class-string<Pageable>|non-empty-string|null $morphModel Accepts a FQCN or a registered morph alias */
        ?string $morphModel = null,
        bool $useCache = true,
        // Ensure cache key is used when modifying query
        ?Closure $modifyQuery = null,
    ): Collection|LengthAwarePaginator {
        return ListPagesAction::run(
            language: $language,
            site: $site,
            page: $page,
            type: $type,
            limit: $limit,
            paginationPage: $paginationPage,
            ordering: $ordering,
            pageType: $pageType,
            pageGroup: $pageGroup,
            typeKey: $typeKey,
            optionalLanguage: $optionalLanguage,
            withChildrenCount: $withChildrenCount,
            withChildren: $withChildren,
            withPagination: $withPagination,
            withParent: $withParent,
            withDate: $withDate,
            onlyListableTypes: $onlyListableTypes,
            paginationKey: $paginationKey,
            morphModel: $morphModel,
            cacheKeyPrepend: $cacheKeyPrepend,
            useCache: $useCache,
            modifyQuery: $modifyQuery,
        );
    }

    public static function getUrlById(string $pageType, int $pageId, Site $site, Language $language, bool $withEvents = true): ?PageUrl
    {
        $key = CacheEnum::urlById($pageType, $pageId, $site->id, $language->id);

        $fromCache = true;

        $url = CapellCore::rememberCache($key, function () use ($site, $language, $pageType, $pageId, $withEvents, &$fromCache): ?PageUrl {
            $fromCache = false;
            $publicPageableMorphTypes = ResolvePublicPageableMorphTypesAction::run();

            if (! in_array($pageType, $publicPageableMorphTypes, true)) {
                return null;
            }

            $callback = fn (): ?PageUrl => PageUrl::query()
                ->where('pageable_type', $pageType)
                ->where('pageable_id', $pageId)
                ->where('site_id', $site->id)
                ->where('language_id', $language->id)
                ->whereIn('pageable_type', $publicPageableMorphTypes)
                ->whereHasMorph(
                    'pageable',
                    $publicPageableMorphTypes,
                    fn (BuilderContract $pageableQuery): BuilderContract => $pageableQuery
                        ->whereHas('blueprint', fn (BuilderContract $query): BuilderContract => $query->enabled()),
                )
                ->enabled()
                ->first();

            if ($withEvents) {
                return $callback();
            }

            return PageUrl::withoutEvents($callback);
        });

        if ($url === null) {
            return null;
        }

        self::setPageUrlRelations($url, $site, $language);
        if ($fromCache) {
            self::trackModelOrCollection($url);
        }

        return $url;
    }

    public static function loadPage(
        string $type,
        int $id,
        Site $site,
        Language $language,
        bool $withEvents = true,
    ): ?Model {
        $modelCache = resolve(PageModelCache::class);
        $page = $modelCache->get($type, $id, $site, $language, $withEvents);

        if (! $page instanceof Pageable) {
            return null;
        }

        /** @var Model&Pageable<Model> $page */
        // Canonical relations (translation, pageUrl, layout, pageUrls) are already
        // loaded by PageModelCache. loadMissing is a safety net for any extras that
        // callers set up outside the canonical set.
        $page->loadMissing([
            'canonicalPage.pageUrls',
            'layout.theme',
            'pageUrls',
            'translation' => fn (BuilderContract $query): BuilderContract => $query->where(
                'language_id',
                $language->id,
            ),
        ]);

        $languages = SiteLoader::languages();

        $page->pageUrls->each(function (PageUrl $pageUrl) use ($languages, $site): void {
            $pageUrl->setRelation('language', $languages->firstWhere('id', $pageUrl->language_id))
                ->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $pageUrl->language_id));
        });

        if ($page->relationLoaded('canonicalPage') && $page->canonicalPage instanceof Model && $page->canonicalPage->relationLoaded('pageUrls')) {
            $page->canonicalPage->pageUrls->each(function (PageUrl $pageUrl) use ($languages, $site): void {
                $pageUrl->setRelation('language', $languages->firstWhere('id', $pageUrl->language_id))
                    ->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $pageUrl->language_id));
            });
        }

        self::trackModelOrCollection($page);

        return $page;
    }

    /**
     * Fetch the next or previous sibling page, depending on direction.
     *
     * @param  Pageable<Model>  $page
     * @return Pageable<Model>|null
     */
    private static function getAdjacentPage(Pageable $page, Site $site, Language $language, string $direction): ?Pageable
    {
        $siblingMethod = $direction === 'next' ? 'nextSiblings' : 'prevSiblings';

        if (! method_exists($page, $siblingMethod)) {
            return null;
        }

        $cacheKey = $direction === 'next'
            ? CacheEnum::pageNext($page->getMorphClass(), $page->getKey(), $site->id, $language->id)
            : CacheEnum::pagePrevious($page->getMorphClass(), $page->getKey(), $site->id, $language->id);

        $fromCache = true;

        $result = CapellCore::rememberCache($cacheKey, function () use ($page, $site, $language, $direction, $siblingMethod, &$fromCache): ?Pageable {
            $fromCache = false;

            $builder = $page->{$siblingMethod}();

            $result = $builder->with([
                'image',
                'blueprint' => fn (Relation $query): Relation => $query->enabled()->listable()->accessible(),
                'pageUrl' => fn (Relation $query): Relation => $query->where('language_id', $language->id),
                'pageUrl.siteDomain',
            ])
                ->where('site_id', $site->id)
                ->withWhereRelation('translation', 'language_id', $language->id)
                ->whereHas(
                    'blueprint',
                    fn (Builder $query): Builder => $query
                        ->enabled()
                        ->listable()
                        ->accessible()
                        ->whereJsonContains('meta->with_next_prev', true),
                )
                ->publishedDate()
                ->when(
                    $page instanceof Page,
                    fn (Builder $query): Builder => $query
                        // @phpstan-ignore method.notFound (The local Page scope is unavailable on the generic Builder contract.)
                        ->notHomePage()
                        ->ordered($direction === 'next' ? 'asc' : 'desc'),
                )
                ->limit(1)
                ->first();

            return $result instanceof Pageable ? $result : null;
        });

        if ($result === null) {
            return null;
        }

        self::setPageRelations($result, $site, $language);

        if ($fromCache) {
            self::trackModelOrCollection($result);
        }

        return $result;
    }

    /**
     * Set all relevant relations on a Page instance.
     *
     * @param  Pageable<Model>|Page  $page
     */
    private static function setPageRelations(Pageable $page, ?Site $site, ?Language $language, bool $withParent = false): void
    {
        if ($site instanceof Site) {
            if (! $page->relationLoaded('site')) {
                $page->setRelation('site', $site);
            }

            if (! $page->pageUrl->relationLoaded('siteDomain')) {
                $page->pageUrl->setRelation('siteDomain', $site->siteDomain);
            }
        }

        if ($language instanceof Language) {
            $page->translation?->setRelation('language', $language);
            $page->pageUrl?->setRelation('language', $language);

            if ($withParent && $page->hasPageHierarchy() && $page->parent !== null) {
                $page->parent->pageUrl?->setRelation('language', $language);
                $page->parent->translation?->setRelation('language', $language);
            }
        }
    }

    /**
     * Set relations on a PageUrl instance.
     */
    private static function setPageUrlRelations(PageUrl $url, Site $site, Language $language): void
    {
        $url->setRelation('site', $site);
        $url->setRelation('language', $language);
    }

    /**
     * @param  Collection<int, Model>|Model  $modelOrCollection
     */
    private static function trackModelOrCollection(Collection|Model $modelOrCollection): void
    {
        if ($modelOrCollection instanceof Collection) {
            $modelOrCollection->each(fn (Model $item) => resolve(RenderedModelTracker::class)->track($item));
        } else {
            resolve(RenderedModelTracker::class)->track($modelOrCollection);
        }
    }
}
