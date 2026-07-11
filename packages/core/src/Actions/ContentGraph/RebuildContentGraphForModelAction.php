<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Models\ContentGraphEdge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsObject;

class RebuildContentGraphForModelAction
{
    use AsObject;

    public function handle(Model $model): void
    {
        DB::transaction(function () use ($model): void {
            PruneContentGraphEdgesAction::run($model);

            foreach (BuildContentGraphForModelAction::run($model)->edges as $edge) {
                $this->store($edge);
            }
        });
    }

    private function store(ContentGraphEdgeData $edge): void
    {
        ContentGraphEdge::query()->updateOrCreate([
            'source_type' => $edge->source->modelType,
            'source_id' => $edge->source->modelId,
            'target_type' => $edge->target->modelType,
            'target_id' => $edge->target->modelId,
            'kind' => is_string($edge->kind) ? $edge->kind : $edge->kind->value,
            'source_package' => $edge->sourcePackage,
        ], [
            'strength' => $edge->strength,
            'site_id' => $edge->siteId ?? $edge->source->siteId ?? $edge->target->siteId,
            'language_id' => $edge->languageId ?? $edge->source->languageId ?? $edge->target->languageId,
            'metadata' => $edge->metadata,
        ]);
    }
}
