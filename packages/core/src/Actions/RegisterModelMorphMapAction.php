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
        $registered = $aliases + Relation::morphMap();
        $canonical = array_filter($registered, fn (string $model, string $alias): bool => $alias !== $model, ARRAY_FILTER_USE_BOTH);
        $morphMap = $canonical + $registered;

        // Strict reads require legacy FQCNs to be explicit keys too. Keep them
        // after canonical aliases so getMorphClass() still writes short names.
        foreach ($aliases as $model) {
            $morphMap[$model] ??= $model;
        }

        Relation::morphMap($morphMap, merge: false);
    }
}
