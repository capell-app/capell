<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static Builder<static> status(bool $enabled)
 * @method static Builder<static> enabled()
 * @method static Builder<static> disabled()
 *
 * @template TModel of Model
 *
 * @mixin Model
 */
trait HasStatus
{
    public function isEnabled(): bool
    {
        return $this->status;
    }

    public function isDisabled(): bool
    {
        return ! $this->status;
    }

    protected static function bootHasStatus(): void
    {
        static::retrieved(function (Model $model): void {
            $model->casts = array_merge(
                $model->casts,
                ['status' => 'boolean'],
            );
        });
    }

    /**
     * @param  Builder<TModel>  $query
     */
    protected function scopeStatus(Builder $query, bool $enabled): void
    {
        $query->where($query->getModel()->qualifyColumn('status'), $enabled);
    }

    /**
     * @param  Builder<TModel>  $query
     */
    protected function scopeEnabled(Builder $query): void
    {
        $query->where($query->getModel()->qualifyColumn('status'), true);
    }

    /**
     * @param  Builder<TModel>  $query
     */
    protected function scopeDisabled(Builder $query): void
    {
        $query->where($query->getModel()->qualifyColumn('status'), false);
    }
}
