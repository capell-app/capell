<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class RegisterModelMorphMapAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, class-string<Model>> $aliases */
    public function handle(array $aliases): void
    {
        $existing = Relation::morphMap();

        // Package aliases retain Laravel's last-registration-wins ownership.
        $registered = $aliases + $existing;

        // Include displaced models from the original map: their persisted FQCNs
        // remain readable, and writes use that unique key when no alias survives.
        foreach ([...array_values($existing), ...array_values($aliases)] as $model) {
            $registered[$model] = $model;
        }

        // Put legacy keys after aliases so surviving aliases keep their write type.
        $canonical = array_filter($registered, fn (string $model, string $alias): bool => $alias !== $model, ARRAY_FILTER_USE_BOTH);
        $morphMap = $canonical + $registered;

        Relation::morphMap($morphMap, merge: false);
    }
}
