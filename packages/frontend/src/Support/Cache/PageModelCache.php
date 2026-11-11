<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Actions\Translations\ResolveTranslationAction;
use Capell\Core\Concerns\HasCache;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\TranslationResolutionData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Contracts\RenderedModelTracker;
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
     */
    public function get(
        string $type,
        int $id,
        ?Site $site,
        Language $language,
        bool $withEvents = true,
        bool $useCache = true,
    ): ?Pageable {
        $modelClass = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($modelClass, Model::class, true) || ! is_a($modelClass, Pageable::class, true)) {
            return null;
        }

        $key = CacheEnum::pageModel($modelClass, $id, $site->id ?? 0, $language->id);

        $fromCache = $useCache;

        $loader = function () use ($modelClass, $id, $site, $language, $withEvents, &$fromCache): ?Pageable {
            $fromCache = false;
            $callback = function () use ($modelClass, $id, $site, $language): ?Pageable {
                $query = $modelClass::query()->where('id', $id)->publishedDate();

                if ($site instanceof Site) {
                    $query->where('site_id', $site->id);
                }

                return $query->with($this->canonicalRelations($modelClass, $language->id))->first();
            };

            if ($withEvents) {
                return $callback();
            }

            return Model::withoutEvents($callback);
        };

        $model = $useCache ? $this->rememberCache($key, $loader) : $loader();

        if ($useCache && $model !== null && ! $model instanceof $modelClass) {
            $this->removeCacheKey($key);
            $model = $this->rememberCache($key, $loader);
        }

        if (! $model instanceof Pageable || ! $model instanceof Model || ! $model instanceof $modelClass) {
            return null;
        }

        if ($site instanceof Site) {
            if ($model->site_id !== $site->id) {
                $this->removeCacheKey($key);

                return null;
            }

            if ($model->relationLoaded('parent') && $model->parent?->site_id !== $site->id) {
                $model->setRelation('parent', null);
            }
        }

        // A cached model is not retrieved, so declare it (and the relations cached with it)
        // as a load would, unless the caller loads without model events.
        if ($fromCache && $withEvents) {
            resolve(RenderedModelTracker::class)->track($model);
        }

        $translationLanguage = $this->applyTranslationFallback($model, $language);

        if ($site instanceof Site) {
            $this->injectTransientRelations($model, $site, $language, $translationLanguage);
        } elseif ($model->translation !== null && $model->pageUrl !== null) {
            $model->translation->setRelation('language', $translationLanguage);
            $model->pageUrl->setRelation('language', $translationLanguage);
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
     * The cached payload holds every translation row, so a page with no translation in
     * the requested language takes its configured fallback here, per request, rather
     * than baking a chain into the cache. The served language's URL replaces the missing
     * requested-language URL. Returns the language the hydrated translation is in.
     *
     * @param  Model&Pageable<Model>  $model
     */
    private function applyTranslationFallback(Pageable $model, Language $language): Language
    {
        $loaded = $model->relationLoaded('translation') ? $model->getRelation('translation') : null;

        if ($loaded instanceof Translation && (int) $loaded->language_id === (int) $language->id) {
            return $language;
        }

        $resolution = ResolveTranslationAction::run($model, $language);

        if (! $resolution instanceof TranslationResolutionData) {
            return $language;
        }

        $model->setRelation('translation', $resolution->translation);

        if (! $resolution->isFallback()) {
            return $language;
        }

        $servedUrl = $model->pageUrls->first(
            fn (PageUrl $url): bool => (int) $url->language_id === $resolution->servedLanguageId
                && $url->type === null
                && $url->status,
        );
        $model->setRelation('pageUrl', $servedUrl);

        $served = $servedUrl instanceof PageUrl && $servedUrl->relationLoaded('language')
            ? $servedUrl->getRelation('language')
            : null;

        return $served instanceof Language ? $served : $language;
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
            'pageUrls.language',
            // The head's hreflang cluster pairs every pageUrl with a real translation row, so
            // `translations` must travel with `pageUrls`: public Blade may only read hydrated
            // relations (PublicViewQueryGuard). Do not drop this to slim the cached payload.
            'translations',
            'image.translations',
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
    private function injectTransientRelations(Pageable $model, Site $site, Language $language, Language $translationLanguage): void
    {
        $model->setRelation('site', $site);

        if ($model->translation instanceof Translation) {
            $model->translation->setRelation('language', $translationLanguage);
        }

        if ($model->pageUrl instanceof PageUrl) {
            $model->pageUrl->setRelation('language', $translationLanguage);
            $model->pageUrl->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $model->pageUrl->language_id));
        }

        // Each URL keeps the language cached with it (`pageUrls.language`): the head's
        // hreflang cluster labels alternates with it, so the request language would mislabel them.
        $model->pageUrls->each(function (PageUrl $url) use ($site, $language): void {
            if (! $url->relationLoaded('language') || ! $url->getRelation('language') instanceof Language) {
                $url->setRelation('language', $language);
            }

            $url->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $url->language_id));
        });

        if ($model->relationLoaded('canonicalPage') && $model->canonicalPage instanceof Model && $model->canonicalPage instanceof Pageable && $model->canonicalPage->relationLoaded('pageUrls')) {
            $model->canonicalPage->pageUrls->each(function (PageUrl $url) use ($site, $language): void {
                $url->setRelation('language', $language);
                $url->setRelation('siteDomain', $site->siteDomains->firstWhere('language_id', $url->language_id));
            });
        }
    }
}
