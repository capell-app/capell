<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Core\Octane\Resettable;
use Capell\Frontend\Data\CacheInvalidationPlanData;
use Capell\Frontend\Data\CacheInvalidationRule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

final class CacheInvalidationRegistry implements Resettable
{
    private array $modelDependencies = [
        Site::class => ['sites', 'site-*', 'site-related-*'],
        Language::class => ['languages', 'page-*', 'site-*'],
        Page::class => ['pages', 'page-*', 'homepage-*', 'page-error-*'],
        'Capell\Core\Models\Navigation' => ['navigation-*', 'site-navigations-*'],
        SiteDomain::class => ['sites', 'site-*'],
    ];

    public function __construct(
        private readonly CacheInvalidationExecutor $executor,
    ) {}

    public function invalidateForModel(string $modelClass): void
    {
        $this->executor->execute($this->planForModel($modelClass));
    }

    public function invalidateChangedModel(Model $model): void
    {
        $this->executor->execute($this->planForChangedModel($model));
    }

    public function planForModel(string $modelClass): CacheInvalidationPlanData
    {
        $patterns = $this->modelDependencies[$modelClass] ?? [];
        $rules = [];

        foreach ($patterns as $pattern) {
            if (str_contains((string) $pattern, '*')) {
                return new CacheInvalidationPlanData([CacheInvalidationRule::flushFrontendTag()]);
            }

            if (is_string($pattern)) {
                $rules[] = CacheInvalidationRule::forgetKey($pattern);
            }
        }

        return new CacheInvalidationPlanData($rules);
    }

    public function planForChangedModel(Model $model): CacheInvalidationPlanData
    {
        if ($model instanceof Page) {
            return new CacheInvalidationPlanData($this->uniqueRules($this->pageRulesWithDependents($model)));
        }

        if ($model instanceof Translation) {
            return $this->planForTranslation($model);
        }

        if ($model instanceof Media && $this->isSiteLogoMedia($model)) {
            return new CacheInvalidationPlanData([
                CacheInvalidationRule::flushFrontendTag(),
            ]);
        }

        $classRules = $this->planForModel($model::class)->rules;
        $pageRules = $this->dependentPages($model::class, (int) $model->getKey())
            ->flatMap(fn (Page $page): array => resolve(PageCacheInvalidator::class)->planForPage($page)->rules)
            ->values()
            ->all();

        return new CacheInvalidationPlanData([
            ...$classRules,
            ...$pageRules,
        ]);
    }

    public function registerDependency(string $modelClass, string|array $cachePatterns): void
    {
        $patterns = is_array($cachePatterns) ? $cachePatterns : [$cachePatterns];
        $this->modelDependencies[$modelClass] = array_merge(
            $this->modelDependencies[$modelClass] ?? [],
            $patterns,
        );
    }

    public function flushOctaneState(): void
    {
        // This class only stores boot-time configuration (class dependencies and cache patterns).
        // The defaults are populated at class instantiation, which happens once per Octane worker.
        // registerDependency() is only called during bootstrap, never during request handling.
        // Therefore, there is no per-request state to reset, and this method is a no-op.
    }

    private function isSiteLogoMedia(Media $media): bool
    {
        return $media->model_type === resolve(Site::class)->getMorphClass()
            && in_array($media->collection_name, [
                MediaCollectionEnum::Logo->value,
                MediaCollectionEnum::LogoInverted->value,
            ], true);
    }

    private function planForTranslation(Translation $translation): CacheInvalidationPlanData
    {
        $translatable = $translation->translatable()->first();

        if ($translatable instanceof Model && $translatable instanceof Pageable) {
            return resolve(PageCacheInvalidator::class)->planForPage($translatable);
        }

        $classRules = $this->planForModel($translation::class)->rules;
        $pageRules = $this->dependentPages($translation::class, (int) $translation->getKey())
            ->flatMap(fn (Page $page): array => resolve(PageCacheInvalidator::class)->planForPage($page)->rules)
            ->values()
            ->all();

        return new CacheInvalidationPlanData([
            ...$classRules,
            ...$pageRules,
        ]);
    }

    /**
     * @return list<CacheInvalidationRule>
     */
    private function pageRulesWithDependents(Page $page): array
    {
        $ownRules = resolve(PageCacheInvalidator::class)->planForPage($page)->rules;
        $dependentRules = $this->dependentPages($page::class, (int) $page->getKey())
            ->reject(fn (Page $dependentPage): bool => (int) $dependentPage->getKey() === (int) $page->getKey())
            ->flatMap(fn (Page $dependentPage): array => resolve(PageCacheInvalidator::class)->planForPage($dependentPage)->rules)
            ->values()
            ->all();

        return [
            ...$ownRules,
            ...$dependentRules,
        ];
    }

    /**
     * @param  list<CacheInvalidationRule>  $rules
     * @return list<CacheInvalidationRule>
     */
    private function uniqueRules(array $rules): array
    {
        $unique = [];

        foreach ($rules as $rule) {
            $uniqueKey = implode('|', [
                $rule->kind,
                $rule->cacheKey ?? '',
                $rule->modelType ?? '',
                (string) ($rule->modelId ?? ''),
                (string) ($rule->siteId ?? ''),
                (string) ($rule->languageId ?? ''),
            ]);

            $unique[$uniqueKey] = $rule;
        }

        return array_values($unique);
    }

    /**
     * @return EloquentCollection<int, Page>
     */
    private function dependentPages(string $targetType, int $targetId): EloquentCollection
    {
        $pageIds = [];
        $queue = [sprintf('%s:%d', $targetType, $targetId)];
        $visited = [];

        while ($queue !== []) {
            $node = array_shift($queue);
            if (! is_string($node)) {
                continue;
            }

            if (isset($visited[$node])) {
                continue;
            }

            $visited[$node] = true;
            [$type, $id] = explode(':', $node, 2);

            ContentGraphEdge::query()
                ->where('target_type', $type)
                ->where('target_id', (int) $id)
                ->get(['source_type', 'source_id'])
                ->each(function (ContentGraphEdge $edge) use (&$pageIds, &$queue): void {
                    if ($edge->source_type === Page::class) {
                        $pageIds[] = $edge->source_id;

                        return;
                    }

                    $queue[] = sprintf('%s:%d', $edge->source_type, $edge->source_id);
                });
        }

        return Page::query()
            ->with('translations')
            ->whereIn('id', array_values(array_unique($pageIds)))
            ->get();
    }
}
