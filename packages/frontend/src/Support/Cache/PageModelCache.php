<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Concerns\HasCache;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Enums\CacheEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

final class PageModelCache
{
    use HasCache;

    /**
     * Fetch a single fully-hydrated model from cache, or load and warm it.
     *
     * When $site is null the cache is partitioned under siteId=0 — only use null when the
     * model is genuinely site-agnostic and the caller guarantees at most one canonical form
     * per (type, id, languageId).
     *
     * @param  class-string<Model&Pageable<Model>>  $type
     * @return (Model&Pageable<Model>)|null
     */
    public function get(string $type, int $id, ?Site $site, Language $language, bool $withEvents = true): ?Pageable
    {
        $key = CacheEnum::pageModel($type, $id, $site->id ?? 0, $language->id);

        $model = $this->rememberCache($key, function () use ($type, $id, $language, $withEvents): ?Pageable {
            /** @var class-string<Model&Pageable<Model>> $modelClass */
            $modelClass = Relation::getMorphedModel($type) ?? $type;

            $callback = fn (): ?Pageable => $modelClass::query()
                ->where('id', $id)
                ->publishedDate()
                ->with($this->canonicalRelations($modelClass, $language->id))
                ->first();

            if ($withEvents) {
                return $callback();
            }

            return Model::withoutEvents($callback);
        });

        if (! $model instanceof Pageable || ! $model instanceof Model) {
            return null;
        }

        if ($site instanceof Site) {
            $this->injectTransientRelations($model, $site, $language);
        } elseif ($model->translation !== null && $model->pageUrl !== null) {
            $model->translation->setRelation('language', $language);
            $model->pageUrl->setRelation('language', $language);
        }

        return $model;
    }

    /**
     * Remove the cached entry for a specific model so the next call re-warms it.
     *
     * @param  class-string  $type
     */
    public function invalidate(string $type, int $id, int $siteId, int $languageId): void
    {
        $this->removeCacheKey(CacheEnum::pageModel($type, $id, $siteId, $languageId));
    }

    /**
     * The set of relations that are always eager-loaded and embedded in the cache.
     *
     * @return array<int|string, mixed>
     */
    private function canonicalRelations(string $modelClass, int $languageId): array
    {
        $relations = [
            'layout.theme',
            'pageUrls',
            'image',
            'blueprint',
            'canonicalPage.pageUrls',
            'translation' => function (Relation $query) use ($languageId): void {
                $query->where('language_id', $languageId);
            },
            'pageUrl' => function (Relation $query) use ($languageId): void {
                $query
                    ->where('language_id', $languageId)
                    ->whereNull('type')
                    ->enabled();
            },
        ];

        if (is_a($modelClass, Page::class, true)) {
            $relations['parent.translation'] = function (Relation $query) use ($languageId): void {
                $query->where('language_id', $languageId);
            };
        }

        return $relations;
    }

    /**
     * Inject runtime relations that must not be persisted in the cache
     * (site object reference, language object reference on child relations).
     */
    /** @param Model&Pageable<Model> $model */
    private function injectTransientRelations(Pageable $model, Site $site, Language $language): void
    {
        $model->setRelation('site', $site);

        if ($model->translation instanceof Translation) {
            $model->translation->setRelation('language', $language);
        }

        if ($model->pageUrl instanceof PageUrl) {
            $model->pageUrl->setRelation('language', $language);
            $model->pageUrl->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $model->pageUrl->language_id));
        }

        $model->pageUrls->each(function (PageUrl $url) use ($site, $language): void {
            $url->setRelation('language', $language);
            $url->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $url->language_id));
        });

        if ($model->relationLoaded('canonicalPage') && $model->canonicalPage instanceof Model && $model->canonicalPage->relationLoaded('pageUrls')) {
            $model->canonicalPage->pageUrls->each(function (PageUrl $url) use ($site, $language): void {
                $url->setRelation('language', $language);
                $url->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $url->language_id));
            });
        }
    }
}
