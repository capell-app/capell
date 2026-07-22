<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Cache;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Data\CacheInvalidationPlanData;
use Capell\Frontend\Data\CacheInvalidationRule;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class CacheInvalidationRegistry
{
    public function __construct(
        private readonly CacheInvalidationDependencyRegistry $dependencies,
        private readonly CacheInvalidationExecutor $executor,
        private readonly TranslationCacheDependencyRegistry $translationDependencies,
    ) {
        foreach ($this->dependencies->allPatterns() as $pattern) {
            $this->executor->registerCacheInvalidationPattern($pattern);
        }
    }

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
        $patterns = $this->dependencies->patternsFor($modelClass);
        $rules = [];

        foreach ($patterns as $pattern) {
            if (str_contains((string) $pattern, '*')) {
                $rules[] = CacheInvalidationRule::invalidatePattern((string) $pattern);

                continue;
            }

            if (is_string($pattern)) {
                $rules[] = CacheInvalidationRule::forgetKey($pattern);
            }
        }

        return new CacheInvalidationPlanData($rules);
    }

    public function planForChangedModel(Model $model): CacheInvalidationPlanData
    {
        try {
            return $this->planForChangedModelWithinBounds($model);
        } catch (RuntimeException) {
            return new CacheInvalidationPlanData([CacheInvalidationRule::flushFrontendTag()]);
        }
    }

    /** @param string|array<string> $cachePatterns */
    public function registerDependency(string $modelClass, string|array $cachePatterns): void
    {
        $this->dependencies->register($modelClass, $cachePatterns);

        foreach ((array) $cachePatterns as $pattern) {
            if (is_string($pattern)) {
                $this->executor->registerCacheInvalidationPattern($pattern);
            }
        }
    }

    private function planForChangedModelWithinBounds(Model $model): CacheInvalidationPlanData
    {
        if ($model instanceof Page) {
            return new CacheInvalidationPlanData($this->uniqueRules($this->pageRulesWithDependents($model)));
        }

        if ($model instanceof Translation) {
            return $this->planForTranslation($model);
        }

        if ($model instanceof Media && $this->isSiteLogoMedia($model)) {
            return new CacheInvalidationPlanData($this->uniqueRules([
                ...$this->planForModel(Site::class)->rules,
                ...$this->planForModel(Page::class)->rules,
            ]));
        }

        $classRules = $this->planForModel($model::class)->rules;
        $pageRules = $this->dependentPages($model::class, (int) $model->getKey())
            ->flatMap(fn (Page $page): array => resolve(PageCacheInvalidator::class)->planForPage($page)->rules)
            ->values()
            ->all();

        return new CacheInvalidationPlanData($this->uniqueRules([
            ...$classRules,
            ...$pageRules,
        ]));
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
        $roots = $this->translationDependencies->roots($translation);

        if ($roots !== []) {
            $rules = [];

            foreach ($roots as $root) {
                $rules = [...$rules, ...$this->rulesForRoot($root)];
            }

            return new CacheInvalidationPlanData($this->uniqueRules($rules));
        }

        $classRules = $this->planForModel($translation::class)->rules;
        $pageRules = $this->dependentPages($translation::class, (int) $translation->getKey())
            ->flatMap(fn (Page $page): array => resolve(PageCacheInvalidator::class)->planForPage($page)->rules)
            ->values()
            ->all();

        return new CacheInvalidationPlanData($this->uniqueRules([
            ...$classRules,
            ...$pageRules,
        ]));
    }

    /** @return list<CacheInvalidationRule> */
    private function rulesForRoot(Model $root): array
    {
        if ($root instanceof Pageable) {
            return $this->pageRulesWithDependents($root);
        }

        if ($root instanceof Media && $this->isSiteLogoMedia($root)) {
            return $this->uniqueRules([
                ...$this->planForModel(Site::class)->rules,
                ...$this->planForModel(Page::class)->rules,
            ]);
        }

        $classRules = $this->planForModel($root::class)->rules;
        $pageRules = $this->dependentPages($root::class, (int) $root->getKey())
            ->flatMap(fn (Page $page): array => resolve(PageCacheInvalidator::class)->planForPage($page)->rules)
            ->values()
            ->all();

        return [...$classRules, ...$pageRules];
    }

    /**
     * @return list<CacheInvalidationRule>
     */
    private function pageRulesWithDependents(Model&Pageable $page): array
    {
        $ownRules = resolve(PageCacheInvalidator::class)->planForPage($page)->rules;
        $dependentRules = $this->dependentPages($page::class, (int) $page->getKey())
            ->reject(fn (Page $dependentPage): bool => $dependentPage::class === $page::class
                && (int) $dependentPage->getKey() === (int) $page->getKey())
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
        $frontier = [[$targetType, $targetId]];
        $visited = [];
        $visitedCount = 0;
        $edgeCount = 0;
        $maxDepth = max(1, (int) config('capell-frontend.cache_invalidation.graph_max_depth', 20));
        $maxNodes = max(1, (int) config('capell-frontend.cache_invalidation.graph_max_nodes', 5000));
        $maxEdges = max(1, (int) config('capell-frontend.cache_invalidation.graph_max_edges', 10000));

        for ($depth = 0; $frontier !== []; $depth++) {
            if ($depth >= $maxDepth) {
                throw new RuntimeException('Content graph invalidation exceeded its maximum traversal depth.');
            }

            $targetsByType = [];

            foreach ($frontier as [$type, $id]) {
                $node = sprintf('%s:%d', $type, $id);

                if (isset($visited[$node])) {
                    continue;
                }

                $visited[$node] = true;
                $visitedCount++;

                if ($visitedCount > $maxNodes) {
                    throw new RuntimeException('Content graph invalidation exceeded its maximum node count.');
                }

                $targetsByType[$type][] = $id;
            }

            $nextFrontier = [];

            foreach ($targetsByType as $type => $ids) {
                ContentGraphEdge::query()
                    ->where('target_type', $type)
                    ->whereIn('target_id', array_values(array_unique($ids)))
                    ->get(['source_type', 'source_id'])
                    ->each(function (ContentGraphEdge $edge) use (&$pageIds, &$nextFrontier, &$edgeCount, $maxEdges): void {
                        $edgeCount++;

                        if ($edgeCount > $maxEdges) {
                            throw new RuntimeException('Content graph invalidation exceeded its maximum edge count.');
                        }

                        if ($edge->source_type === Page::class) {
                            $pageIds[] = $edge->source_id;

                            return;
                        }

                        $nextFrontier[] = [$edge->source_type, $edge->source_id];
                    });
            }

            $frontier = $nextFrontier;
        }

        return Page::query()
            ->with('translations')
            ->whereIn('id', array_values(array_unique($pageIds)))
            ->get();
    }
}
