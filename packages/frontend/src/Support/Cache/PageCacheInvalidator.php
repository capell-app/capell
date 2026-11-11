<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

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

        // What loads now is the state after the change. The languages, site and blueprint
        // the old content was cached under come from the pre-change snapshot (a deleted
        // page's translations, recorded before they were deleted) and from the changed
        // translation. Each language also reaches the languages falling back to it: those
        // render this content too.
        $snapshots = resolve(PreChangeInvalidationSnapshots::class);
        $languageIds = collect([
            ...$model->translations->map(fn (Model $translation): int => (int) $translation->getAttribute('language_id'))->all(),
            ...$snapshots->languages($model),
            ...$changedLanguageIds,
        ])
            ->flatMap(fn (int $languageId): array => [$languageId, ...ResolveLanguagesFallingBackToAction::run($languageId)])
            ->unique()
            ->values()
            ->all();
        $siteIds = $this->ids([$model->getAttribute('site_id'), ...$snapshots->originalIds($model, 'site_id')]);

        foreach ($siteIds as $siteId) {
            foreach ($languageIds as $languageId) {
                $rules[] = CacheInvalidationRule::pageModel($type, $id, $siteId, $languageId);
                $rules[] = CacheInvalidationRule::pageListing($siteId, $languageId);
                $rules[] = CacheInvalidationRule::publicRenderData($type, $id, $siteId, $languageId);
            }
        }

        if ($model instanceof Page) {
            $rules = [...$rules, ...$this->siteEntryPageRules($model, $siteIds, $languageIds, $snapshots)];
        }

        return new CacheInvalidationPlanData($rules);
    }

    /**
     * System pages (404, error, maintenance) and the home page are cached per site and
     * language, and a language without its own is served another language's through the
     * fallback chain. Which page answers can change with any create, edit or delete of a
     * page of that blueprint type, so the entries for its type before and after the change
     * are forgotten for the languages it was and is in. Plain key deletes: ordinary
     * invalidation stays best effort.
     *
     * @param  list<int>  $siteIds
     * @param  list<int>  $languageIds
     * @return list<CacheInvalidationRule>
     */
    private function siteEntryPageRules(Page $page, array $siteIds, array $languageIds, PreChangeInvalidationSnapshots $snapshots): array
    {
        $types = $this->blueprintKeys($page, $snapshots);

        if ($types === [] || $languageIds === []) {
            return [];
        }

        $rules = [];

        foreach ($siteIds as $siteId) {
            foreach ($languageIds as $languageId) {
                foreach ($types as $type) {
                    $rules[] = CacheInvalidationRule::forgetKey(CacheEnum::systemPage($type, $siteId, $languageId));
                }

                if (in_array(self::HOME_BLUEPRINT_KEY, $types, true)) {
                    $rules[] = CacheInvalidationRule::forgetKey(CacheEnum::homePage($siteId, $languageId));
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
    private function blueprintKeys(Page $page, PreChangeInvalidationSnapshots $snapshots): array
    {
        $blueprintIds = $this->ids([$page->getAttribute('blueprint_id'), ...$snapshots->originalIds($page, 'blueprint_id')]);
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
     * @param  array<int, mixed>  $values
     * @return list<int>
     */
    private function ids(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
