<?php

declare(strict_types=1);

namespace Capell\Core\Models\Scopes;

use Capell\Core\Models\Contracts\Userstampable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * @implements Scope<Model>
 */
class UserstampsScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void {}

    /**
     * @param  Builder<Model>  $builder
     */
    public function extend(Builder $builder): void
    {
        $builder->macro('updateWithUserstamps', function (Builder $builder, array $values): int {
            $model = $builder->getModel();

            if (! $model instanceof Userstampable || ! $model->isUserstamping() || Auth::id() === null) {
                return $builder->update($values);
            }

            $values[$model->getUpdatedByColumn()] = Auth::id();

            return $builder->update($values);
        });

        $builder->macro('deleteWithUserstamps', function (Builder $builder): int|bool|null {
            $model = $builder->getModel();

            if (! $model instanceof Userstampable || ! $model->isUserstamping() || Auth::id() === null) {
                return $builder->delete();
            }

            $builder->update([
                $model->getDeletedByColumn() => Auth::id(),
            ]);

            return $builder->delete();
        });
    }
}
