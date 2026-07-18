<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Capell\Core\Models\ContentGraphEdge;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class PruneContentGraphEdgesAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $source): void
    {
        ContentGraphEdge::query()
            ->where('source_type', $source::class)
            ->where('source_id', $source->getKey())
            ->delete();
    }
}
