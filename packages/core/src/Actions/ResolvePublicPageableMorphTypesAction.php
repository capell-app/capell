<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePublicPageableMorphTypesAction
{
    use AsFake;
    use AsObject;

    /** @return list<class-string<Model>|string> */
    public function handle(): array
    {
        return array_values(collect(Relation::morphMap())
            ->filter(fn (string $modelClass): bool => is_subclass_of($modelClass, Model::class)
                && is_subclass_of($modelClass, Pageable::class))
            ->flatMap(fn (string $modelClass, string $alias): array => [$alias, $modelClass])
            ->unique()
            ->values()
            ->all());
    }
}
