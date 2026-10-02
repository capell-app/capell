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

    /**
     * Model classes whose backing table is known to exist. Only presence is
     * remembered: the morph map holds an alias and the class name for each
     * model, and the action runs more than once per request, but a table that
     * is missing now may be migrated later in the same process (install).
     *
     * @var array<class-string<Model>, true>
     */
    private array $modelsWithTable = [];

    /** @return list<class-string<Model>|string> */
    public function handle(): array
    {
        return array_values(collect(Relation::morphMap())
            ->filter(fn (string $modelClass): bool => is_subclass_of($modelClass, Model::class)
                && is_subclass_of($modelClass, Pageable::class)
                && $this->hasBackingTable($modelClass))
            ->flatMap(fn (string $modelClass, string $alias): array => [$alias, $modelClass])
            ->unique()
            ->values()
            ->all());
    }

    /** @param class-string<Model> $modelClass */
    private function hasBackingTable(string $modelClass): bool
    {
        if (isset($this->modelsWithTable[$modelClass])) {
            return true;
        }

        $model = new $modelClass;

        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            return false;
        }

        $this->modelsWithTable[$modelClass] = true;

        return true;
    }
}
