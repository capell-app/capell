<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData;
use Capell\Core\Data\ContentGraph\ContentGraphEdgeData;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildContentGraphForModelAction
{
    use AsObject;

    public function handle(Model $model): ContentGraphEdgeCollectionData
    {
        /** @var ContentGraphRegistry $registry */
        $registry = resolve(ContentGraphRegistry::class);

        /** @var array<int, ContentGraphEdgeData> $edges */
        $edges = [];

        foreach ($registry->forModel($model::class) as $extractor) {
            array_push($edges, ...$extractor->extract($model)->edges);
        }

        return ContentGraphEdgeCollectionData::make($edges);
    }
}
