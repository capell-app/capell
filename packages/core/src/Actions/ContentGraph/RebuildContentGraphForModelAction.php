<?php

declare(strict_types=1);

namespace Capell\Core\Actions\ContentGraph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class RebuildContentGraphForModelAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $model): void
    {
        DB::transaction(function () use ($model): void {
            PruneContentGraphEdgesAction::run($model);

            foreach (BuildContentGraphForModelAction::run($model)->edges as $edge) {
                StoreContentGraphEdgeAction::run($edge);
            }
        });
    }
}
