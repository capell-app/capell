<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ?Model run(string $pageableType, int|string $pageableId, array<int, string> $columns = ['*'])
 */
class ResolvePageableMorphModelAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<int, string>  $columns
     */
    public function handle(string $pageableType, int|string $pageableId, array $columns = ['*']): ?Model
    {
        $modelClass = Relation::getMorphedModel($pageableType) ?? $pageableType;

        if (! is_subclass_of($modelClass, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $modelQuery = $modelClass::query();

        $resolvedModel = $modelQuery->find($pageableId, $columns);

        return $resolvedModel instanceof Model ? $resolvedModel : null;
    }
}
