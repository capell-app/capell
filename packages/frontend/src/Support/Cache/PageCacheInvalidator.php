<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Actions\Translations\ResolveLanguageFallbackChainAction;
use Capell\Core\Actions\Translations\ResolveLanguagesFallingBackToAction;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Frontend\Data\CacheInvalidationPlanData;
use Capell\Frontend\Data\CacheInvalidationRule;
use Capell\Frontend\Enums\CacheEnum;
use Illuminate\Database\Eloquent\Model;

final class PageCacheInvalidator
{
    private const string HOME_BLUEPRINT_KEY = 'home';

    public function __construct(
        private readonly CacheInvalidationExecutor $executor,
    ) {}

    /**
     * Called after a Pageable model is created, updated, or deleted.
     * Invalidates both the individual model caches and the listing ID caches
     * for all languages the model has a translation in.
     */
    public function invalidate(Model&Pageable $model): void
    {
        $this->onSaved($model);
    }

    public function onSaved(Model&Pageable $model): void
    {
        $this->executor->execute($this->planForPage($model));
    }

    /**
     * @param  list<int>  $changedLanguageIds  Languages a changed translation was in, before
     *                                         and after the change: a deleted or moved row
     *                                         is no longer among the page's translations.
     */
    public function planForPage(Model&Pageable $model, array $changedLanguageIds = []): CacheInvalidationPlanData
    {
        $siteId = (int) $model->getAttribute('site_id');
        $type = $model::class;
        $id = (int) $model->getKey();
        $rules = [];

        // Callers resolve the Pageable root through different paths — some
        // (CacheInvalidationRegistry::dependentPages()) eager-load translations,
        // others (PageableTranslationCacheDependencyResolver::owner(), reached
        // when a Translation itself is saved) only load the owning model via its
        // morph relation. This method is the one with the actual requirement, so
        // it guarantees it here rather than trusting every caller to remember.
        $model->loadMissing('translations');

        // The translations loaded here are the ones left after the change, so a deleted
        // page (its translations are deleted with it) or a deleted translation would drop
        // out. A deleted page is retired in every language; otherwise the languages it
        // lives in plus those of the changed translation. Each also reaches the languages
        // falling back to it: those render this content too.
        $allLanguageIds = $this->allLanguageIds();
        $ownLanguageIds = $this->isDeleted($model)
            ? $allLanguageIds
            : [
                ...$model->translations->map(fn (Model $translation): int => (int) $translation->getAttribute('language_id'))->all(),
                ...$changedLanguageIds,
            ];

        $languageIds = collect($ownLanguageIds)
            ->flatMap(fn (int $languageId): array => [$languageId, ...ResolveLanguagesFallingBackToAction::run($languageId)])
            ->unique()
            ->values();

        $languageIds->each(function (int $languageId) use (&$rules, $type, $id, $siteId): void {
            $rules[] = CacheInvalidationRule::pageModel($type, $id, $siteId, $languageId);
            $rules[] = CacheInvalidationRule::pageListing($siteId, $languageId);
            $rules[] = CacheInvalidationRule::publicRenderData($type, $id, $siteId, $languageId);
        });

        if ($model instanceof Page) {
            $rules = [...$rules, ...$this->siteEntryPageRules($model, $siteId, $allLanguageIds)];
        }

        return new CacheInvalidationPlanData($rules);
    }

    /**
     * System pages (404, error, maintenance) and the home page are cached per site and
     * language, and a language without its own is served another language's through the
     * fallback chain. Which page answers can change with any create, edit or delete of a
     * page of that blueprint type, so the entries for its type before and after the change
     * are forgotten in every language, whatever translations remain. Plain key deletes:
     * ordinary invalidation stays best effort.
     *
     * @param  list<int>  $languageIds
     * @return list<CacheInvalidationRule>
     */
    private function siteEntryPageRules(Page $page, int $siteId, array $languageIds): array
    {
        $types = $this->blueprintKeys($page);

        if ($types === [] || $languageIds === []) {
            return [];
        }

        $siteIds = array_values(array_unique(array_filter([
            $siteId,
            $page->wasChanged('site_id') ? (int) ($page->getPrevious()['site_id'] ?? 0) : 0,
        ])));
        $rules = [];

        foreach ($siteIds as $entrySiteId) {
            foreach ($languageIds as $languageId) {
                foreach ($types as $type) {
                    $rules[] = CacheInvalidationRule::forgetKey(CacheEnum::systemPage($type, $entrySiteId, $languageId));
                }

                if (in_array(self::HOME_BLUEPRINT_KEY, $types, true)) {
                    $rules[] = CacheInvalidationRule::forgetKey(CacheEnum::homePage($entrySiteId, $languageId));
                }
            }
        }

        return $rules;
    }

    /**
     * The page's blueprint key before and after the change, from the loaded relation when
     * it matches and otherwise from one query.
     *
     * @return list<string>
     */
    private function blueprintKeys(Page $page): array
    {
        $blueprintIds = array_values(array_unique(array_map(intval(...), array_filter([
            $page->getAttribute('blueprint_id'),
            $page->wasChanged('blueprint_id') ? $page->getPrevious()['blueprint_id'] ?? null : null,
        ], static fn (mixed $id): bool => $id !== null))));

        $keys = [];
        $loaded = $page->relationLoaded('blueprint') ? $page->getRelation('blueprint') : null;

        if ($loaded instanceof Blueprint && in_array((int) $loaded->getKey(), $blueprintIds, true)) {
            $keys[] = (string) $loaded->key;
            $blueprintIds = array_values(array_diff($blueprintIds, [(int) $loaded->getKey()]));
        }

        if ($blueprintIds !== []) {
            $keys = [...$keys, ...Blueprint::query()->whereKey($blueprintIds)->pluck('key')->map(static fn (mixed $key): string => (string) $key)->all()];
        }

        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
    }

    /**
     * Every language, enabled or not, from the cached language map: a superset of any
     * site's languages at no query, and it still covers a language disabled in this change.
     *
     * @return list<int>
     */
    private function allLanguageIds(): array
    {
        return array_keys(ResolveLanguageFallbackChainAction::make()->languageMap());
    }

    private function isDeleted(Model $model): bool
    {
        if (! $model->exists) {
            return true;
        }

        return method_exists($model, 'trashed') && $model->trashed();
    }
}
