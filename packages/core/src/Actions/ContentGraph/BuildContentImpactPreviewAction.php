<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Data\ContentGraph\ContentImpactGroupData;
use Capell\Core\Data\ContentGraph\ContentImpactPreviewData;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildContentImpactPreviewAction
{
    use AsObject;

    public function handle(Model $target): ContentImpactPreviewData
    {
        $dependentEdges = FindContentGraphDependentsAction::run(
            $target::class,
            (int) $target->getKey(),
        );

        $sourceRecords = $this->buildSourceRecords($dependentEdges);

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
     * @return Collection<int, array{modelType: string, recordId: int, strongestStrength: ContentGraphEdgeStrength}>
     */
    private function buildSourceRecords(EloquentCollection $dependentEdges): Collection
    {
        return $dependentEdges
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
    }

    /**
     * @param  Collection<int, array{modelType: string, recordId: int, strongestStrength: ContentGraphEdgeStrength}>  $sourceRecords
     * @return array<int, ContentImpactGroupData>
     */
    private function buildGroups(Collection $sourceRecords): array
    {
        return $sourceRecords
            ->groupBy('modelType')
            ->map(function (Collection $groupedSourceRecords, string $modelType): ContentImpactGroupData {
                $recordIds = $groupedSourceRecords
                    ->pluck('recordId')
                    ->values()
                    ->all();

                return new ContentImpactGroupData(
                    label: Str::plural(class_basename($modelType)),
                    modelType: $modelType,
                    strongestStrength: $this->strongestStrength($groupedSourceRecords->pluck('strongestStrength')->all()),
                    count: count($recordIds),
                    recordIds: $recordIds,
                );
            })
            ->values()
            ->all();
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
