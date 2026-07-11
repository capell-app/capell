<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Loader;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Frontend\Contracts\RenderedModelTracker;
use Capell\Frontend\Enums\CacheEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

class SiteLoader
{
    /**
     * Get all languages
     *
     * @return \Illuminate\Support\Collection<int, Language> Collection of unique languages
     */
    public static function languages(): \Illuminate\Support\Collection
    {
        /** @var class-string<Language> $model */
        $model = Language::class;

        // Prime the autoloader for the resolved (possibly user-overridden)
        // Language class BEFORE the cache read. If the closure below doesn't
        // run (cache hit) the class is never referenced on this request, and
        // `unserialize()` on the cached payload can produce
        // `__PHP_Incomplete_Class` — failing the return-type check on this
        // method with a fatal TypeError on every second request.
        class_exists($model);

        return CapellCore::rememberCache(
            CacheEnum::Languages->value,
            fn (): \Illuminate\Support\Collection => $model::query()
                ->enabled()
                ->ordered()
                ->whereHas('sites')
                ->get(),
        );
    }

    /**
     * Get minimal site data (just siteDomains + translations for early request stages).
     * Full site loading (theme, media, type) is deferred to RenderPipeline via loadSite().
     *
     * @return Collection<int, Site>
     */
    public static function getSites(): Collection
    {
        // Prime the autoloader for the resolved (possibly user-overridden) Site
        // class before the cache read. See the comment on languages() for why.
        class_exists(Site::class);

        if (! Schema::hasTable((new Site)->getTable())) {
            return (new Site)->newCollection();
        }

        $fromCache = true;

        $sites = CapellCore::rememberCache(CacheEnum::Sites->value, function () use (&$fromCache): Collection {
            $fromCache = false;

            return Site::query()
                ->whereHas(
                    'siteDomains',
                    fn (Builder|Relation $query): Builder|Relation => $query->whereHas(
                        'language',
                        fn (Builder|Relation $query): Builder|Relation => $query->enabled(),
                    )
                        ->enabled(),
                )
                ->with([
                    'siteDomains',
                    'translations',
                ])
                ->get();
        });

        $siteLanguages = SiteLoader::languages();
        /** @var \Illuminate\Support\Collection<int, Language> $languagesById */
        $languagesById = $siteLanguages->keyBy('id');
        $store = $fromCache ? resolve(RenderedModelTracker::class) : null;

        $sites->each(function (Site $site) use ($languagesById, $store): void {
            if ($store !== null) {
                $store->track($site);
            }

            $site->siteDomains->each(function (SiteDomain $siteDomain) use ($languagesById, $store): void {
                $siteDomain->setRelation('language', $languagesById->get($siteDomain->language_id));
                if ($store !== null) {
                    $store->track($siteDomain);
                }
            });

            $site->translations->each(function (Translation $translation) use ($languagesById, $store): void {
                $translation->setRelation('language', $languagesById->get($translation->language_id));
                if ($store !== null) {
                    $store->track($translation);
                }
            });
        });

        return $sites;
    }

    public static function loadSite(Site $site, Language $language): ?Site
    {
        // Prime the autoloader for the resolved (possibly user-overridden) Site
        // class before the cache read. See the comment on languages() for why.
        class_exists(Site::class);

        $key = CacheEnum::site($site->id, $language->id);

        $fromCache = true;

        $site = CapellCore::rememberCache($key, function () use ($site, $language, &$fromCache): ?Site {
            $fromCache = false;

            $relations = [
                'language',
                'media',
                'theme.media',
                'type',
            ];

            if ($site->isRelation('navigations')) {
                $relations['navigations'] = fn (Relation $query): Relation => $query->where(
                    fn (Builder $query): Builder => $query->where('language_id', $language->id)
                        ->orWhereNull('language_id'),
                );
            }

            return $site->loadMissing($relations);
        });

        if ($fromCache && $site !== null) {
            resolve(RenderedModelTracker::class)->track($site);

            if ($site->relationLoaded('media')) {
                foreach ($site->media as $media) {
                    resolve(RenderedModelTracker::class)->track($media);
                }
            }

            self::trackLoadedModelRelation($site, 'theme');
            if ($site->theme?->relationLoaded('media')) {
                foreach ($site->theme->media as $media) {
                    resolve(RenderedModelTracker::class)->track($media);
                }
            }

            self::trackLoadedModelRelation($site, 'language');
            self::trackLoadedModelRelation($site, 'type');
        }

        self::loadSiteRelation($site);

        return $site;
    }

    /**
     * @return array<int, array{id: int, flag: string, name: string, url: string|false}>
     */
    public static function pageLanguages(Site $site, Language $language, Pageable $page): array
    {
        $key = CacheEnum::pageLanguages($page->id, $language->id);

        return CapellCore::rememberCache($key, function () use ($site, $language, $page): array {
            $siteLanguages = SiteLoader::languages();

            if ($siteLanguages->count() === 1) {
                return [
                    [
                        'id' => $language->id,
                        'code' => $language->code,
                        'flag' => $language->flag,
                        'name' => $language->name,
                        'url' => $page->pageUrl->full_url,
                    ],
                ];
            }

            return $siteLanguages
                ->map(function (Language $siteLanguage) use ($site, $language, $page): ?array {
                    $url = null;

                    if (! isset($page->type->meta['accessible']) || $page->type->meta['accessible'] !== false) {
                        $url = $page->pageUrls->firstWhere('language_id', $siteLanguage->id);
                        $url = $url?->full_url;
                    }

                    if ($url === null) {
                        $home = PageLoader::getSiteHomePage($site, $language);
                        if ($home instanceof Pageable) {
                            $url = $home->pageUrl->full_url;
                        }
                    }

                    if ($url === null) {
                        return null;
                    }

                    return [
                        'id' => $siteLanguage->id,
                        'code' => $siteLanguage->code,
                        'flag' => $siteLanguage->flag,
                        'name' => $siteLanguage->name,
                        'url' => $url,
                    ];
                })
                ->filter()
                ->values()
                ->all();
        });
    }

    /**
     * @return Collection<int, Site>
     */
    public static function related(Site $site, Language $language): Collection
    {
        // Prime the autoloader for the resolved (possibly user-overridden) Site
        // class before the cache read. See the comment on languages() for why.
        class_exists(Site::class);

        $key = CacheEnum::siteRelated($site->id, $language->id);

        $fromCache = true;

        $sites = CapellCore::rememberCache($key, function () use ($site, $language, &$fromCache): Collection {
            $fromCache = false;

            return $site->related()
                ->withWhereHas(
                    'siteDomain',
                    fn (Builder|Relation $query): Builder|Relation => $query->where('language_id', $language->id),
                )
                ->with([
                    'theme',
                    'translation' => fn (Relation $query): Relation => $query->where('language_id', $language->id),
                ])
                ->get();
        });

        if ($fromCache) {
            $sites->each(function (Site $site): void {
                resolve(RenderedModelTracker::class)->track($site);

                self::loadSiteRelation($site);
            });
        }

        return $sites;
    }

    private static function trackLoadedModelRelation(Model $model, string $relation): void
    {
        if (! $model->relationLoaded($relation)) {
            return;
        }

        $related = $model->getRelation($relation);

        if ($related instanceof Model) {
            resolve(RenderedModelTracker::class)->track($related);
        }
    }

    private static function loadSiteRelation(Site $site): void
    {
        foreach (['translations', 'siteDomains'] as $relation) {
            if ($site->relationLoaded($relation)) {
                foreach ($site->{$relation} as $item) {
                    resolve(RenderedModelTracker::class)->track($item);
                }
            }
        }

        foreach (['theme', 'type'] as $relation) {
            if ($site->relationLoaded($relation) && $site->{$relation} !== null) {
                resolve(RenderedModelTracker::class)->track($site->{$relation});
            }
        }

        foreach (['image', 'logo', 'logoInverted'] as $relation) {
            if ($site->relationLoaded($relation)) {
                continue;
            }

            $site->setRelation($relation, $site->media->firstWhere('collection_name', $relation));

            if ($site->{$relation} !== null) {
                resolve(RenderedModelTracker::class)->track($site->{$relation});
            }
        }
    }
}
