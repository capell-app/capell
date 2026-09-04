<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\ContentGraph\ContentImpactGroupData;
use Capell\Core\Data\ContentGraph\ContentImpactPreviewData;
use Capell\Core\Data\EditorImpact\EditorImpactDependencyData;
use Capell\Core\Data\EditorImpact\EditorImpactUrlData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Exceptions\UrlMissingSiteDomainException;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildContentImpactPreviewAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  (Closure(Model): bool)|null  $visible
     */
    public function handle(Model $target, ?Closure $visible = null): ContentImpactPreviewData
    {
        $dependentEdges = FindContentGraphDependentsAction::run(
            $target::class,
            (int) $target->getKey(),
        );

        $sourceRecords = $this->resolveSourceRecords($dependentEdges, $visible);

        return new ContentImpactPreviewData(
            blocked: $sourceRecords->contains(
                fn (array $sourceRecord): bool => $sourceRecord['strongestStrength'] === ContentGraphEdgeStrength::Strong,
            ),
            strongCount: $sourceRecords->where('strongestStrength', ContentGraphEdgeStrength::Strong)->count(),
            weakCount: $sourceRecords->where('strongestStrength', ContentGraphEdgeStrength::Weak)->count(),
            informationalCount: $sourceRecords->where('strongestStrength', ContentGraphEdgeStrength::Informational)->count(),
            groups: $this->buildGroups($sourceRecords),
        );
    }

    /**
     * @param  EloquentCollection<int, ContentGraphEdge>  $dependentEdges
     * @param  (Closure(Model): bool)|null  $visible
     * @return Collection<int, array{model: Model, strongestStrength: ContentGraphEdgeStrength}>
     */
    private function resolveSourceRecords(EloquentCollection $dependentEdges, ?Closure $visible): Collection
    {
        $identities = $dependentEdges
            ->groupBy(fn (ContentGraphEdge $dependentEdge): string => $dependentEdge->source_type . '|' . $dependentEdge->source_id)
            ->map(function (EloquentCollection $sourceRecordEdges): array {
                /** @var ContentGraphEdge $firstEdge */
                $firstEdge = $sourceRecordEdges->first();

                return [
                    'modelType' => $firstEdge->source_type,
                    'recordId' => $firstEdge->source_id,
                    'strongestStrength' => $this->strongestStrength($sourceRecordEdges->pluck('strength')->all()),
                ];
            })
            ->values();

        /** @var Collection<int, array{model: Model, strongestStrength: ContentGraphEdgeStrength}> $sourceRecords */
        $sourceRecords = collect();

        foreach ($identities->groupBy('modelType') as $modelType => $group) {
            if (! is_string($modelType)) {
                continue;
            }

            if (! is_a($modelType, Model::class, true)) {
                continue;
            }

            /** @var class-string<Model> $modelType */
            $models = $modelType::query()
                ->whereKey($group->pluck('recordId')->all())
                ->get()
                ->keyBy(fn (Model $model): string => (string) $model->getKey());

            foreach ($group as $identity) {
                $model = $models->get((string) $identity['recordId']);

                if (! $model instanceof Model) {
                    continue;
                }

                if ($visible instanceof Closure && ! $visible($model)) {
                    continue;
                }

                $this->loadImpactRelations($model);

                $sourceRecords->push([
                    'model' => $model,
                    'strongestStrength' => $identity['strongestStrength'],
                ]);
            }
        }

        return $sourceRecords;
    }

    /**
     * @param  Collection<int, array{model: Model, strongestStrength: ContentGraphEdgeStrength}>  $sourceRecords
     * @return array<int, ContentImpactGroupData>
     */
    private function buildGroups(Collection $sourceRecords): array
    {
        return $sourceRecords
            ->groupBy(fn (array $sourceRecord): string => $sourceRecord['model']::class)
            ->map(function (Collection $groupedSourceRecords, string $modelType): ContentImpactGroupData {
                $dependencies = $groupedSourceRecords
                    ->map(fn (array $sourceRecord): EditorImpactDependencyData => $this->dependencyData(
                        $sourceRecord['model'],
                        $sourceRecord['strongestStrength'],
                    ))
                    ->sortBy(fn (EditorImpactDependencyData $dependency): string => $dependency->site . '|' . $dependency->name . '|' . $dependency->type)
                    ->values();

                return new ContentImpactGroupData(
                    label: Str::plural(Str::headline(class_basename($modelType))),
                    strongestStrength: $this->strongestStrength($groupedSourceRecords->pluck('strongestStrength')->all()),
                    count: $dependencies->count(),
                    dependencies: array_values($dependencies->all()),
                );
            })
            ->values()
            ->all();
    }

    private function loadImpactRelations(Model $model): void
    {
        if ($model instanceof Pageable) {
            $model->loadMissing(['site', 'pageUrls.language', 'pageUrls.siteDomain']);

            return;
        }

        if (method_exists($model, 'site')) {
            $model->loadMissing('site');
        }
    }

    private function dependencyData(Model $model, ContentGraphEdgeStrength $strength): EditorImpactDependencyData
    {
        $urls = $model instanceof Pageable
            ? $this->pageUrls($model)
            : collect();

        return new EditorImpactDependencyData(
            name: $this->name($model),
            type: Str::headline(class_basename($model)),
            site: $this->siteName($model),
            locales: array_values($urls->pluck('locale')->unique()->values()->all()),
            urls: array_values($urls->all()),
            strength: $strength,
            consequence: $this->consequence($strength),
        );
    }

    /**
     * @param  Model&Pageable<Model>  $page
     * @return Collection<int, EditorImpactUrlData>
     */
    private function pageUrls(Model&Pageable $page): Collection
    {
        /** @var EloquentCollection<int, PageUrl> $pageUrls */
        $pageUrls = $page->getRelation('pageUrls');

        return $pageUrls
            ->filter(fn (PageUrl $pageUrl): bool => $pageUrl->site_id === $page->getAttribute('site_id')
                && $pageUrl->status
                && ! $pageUrl->isRedirect()
                && $this->hasActiveSiteDomain($pageUrl))
            ->map(fn (PageUrl $pageUrl): ?EditorImpactUrlData => $this->urlData($pageUrl))
            ->filter(fn (?EditorImpactUrlData $url): bool => $url instanceof EditorImpactUrlData)
            ->sortBy(fn (EditorImpactUrlData $url): string => $url->locale . '|' . $url->url)
            ->values();
    }

    private function hasActiveSiteDomain(PageUrl $pageUrl): bool
    {
        $siteDomain = $pageUrl->getRelation('siteDomain');

        return $siteDomain instanceof SiteDomain && $siteDomain->status;
    }

    private function urlData(PageUrl $pageUrl): ?EditorImpactUrlData
    {
        try {
            $url = $pageUrl->fullUrl();
        } catch (UrlMissingSiteDomainException) {
            return null;
        }

        $language = $pageUrl->getRelation('language');
        $locale = $language instanceof Language
            ? ((string) ($language->locale ?: $language->code ?: $language->name))
            : '';

        return $locale === '' ? null : new EditorImpactUrlData($locale, $url);
    }

    private function name(Model $model): string
    {
        foreach (['name', 'title'] as $attribute) {
            $value = $model->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return (string) __('capell-core::generic.unnamed_content', ['type' => Str::headline(class_basename($model))]);
    }

    private function siteName(Model $model): string
    {
        if ($model instanceof Site) {
            return $this->name($model);
        }

        if (! $model->relationLoaded('site')) {
            return (string) __('capell-core::generic.unknown_site');
        }

        $site = $model->getRelation('site');

        return $site instanceof Site && is_string($site->name) && $site->name !== ''
            ? $site->name
            : (string) __('capell-core::generic.unknown_site');
    }

    private function consequence(ContentGraphEdgeStrength $strength): string
    {
        return (string) __('capell-core::generic.content_impact_consequence_' . $strength->value);
    }

    /**
     * @param  array<int, ContentGraphEdgeStrength>  $strengths
     */
    private function strongestStrength(array $strengths): ContentGraphEdgeStrength
    {
        if (in_array(ContentGraphEdgeStrength::Strong, $strengths, true)) {
            return ContentGraphEdgeStrength::Strong;
        }

        if (in_array(ContentGraphEdgeStrength::Weak, $strengths, true)) {
            return ContentGraphEdgeStrength::Weak;
        }

        return ContentGraphEdgeStrength::Informational;
    }
}
