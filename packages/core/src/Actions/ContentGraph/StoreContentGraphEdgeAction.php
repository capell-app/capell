<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Models\ContentGraphEdge;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Persists one extracted edge, updating the stored edge with the same identity. */
class StoreContentGraphEdgeAction
{
    use AsFake;
    use AsObject;

    public function handle(ContentGraphEdgeData $edge): void
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
