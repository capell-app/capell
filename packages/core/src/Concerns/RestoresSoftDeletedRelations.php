<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;

trait RestoresSoftDeletedRelations
{
    /**
     * @param  list<string>  $relations
     */
    protected function restoreSoftDeletedRelations(Model $model, array $relations): void
    {
        foreach ($relations as $relation) {
            $this->restoreSoftDeletedRelation($model, $relation);
        }
    }

    protected function restoreSoftDeletedRelation(Model $model, string $relation): void
    {
        if (! method_exists($model, $relation)) {
            return;
        }

        $relatedRelation = $model->{$relation}();

        if (! $relatedRelation instanceof Relation) {
            return;
        }

        $related = $relatedRelation->getRelated();

        if (! in_array(SoftDeletes::class, class_uses_recursive($related), true)) {
            return;
        }

        $relatedRelation->onlyTrashed()->restore();
    }
}
