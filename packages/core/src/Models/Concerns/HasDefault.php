<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @method static Builder<static> default(bool $default = true)
 * @method static Builder<static> nonDefault()
 *
 * @template TModel of Model
 *
 * @mixin Model
 */
trait HasDefault
{
    public static function getDefault(): ?self
    {
        return CapellCore::rememberCache(
            CacheEnum::modelDefaultKey(static::class),
            fn (): ?self => self::query()->default()->first(),
        );
    }

    public function isDefault(): bool
    {
        return (bool) $this->getAttributeValue('default');
    }

    protected static function bootHasDefault(): void
    {
        static::retrieved(function (Model $model): void {
            $model->casts = array_merge(
                $model->casts,
                ['default' => 'boolean'],
            );
        });

        static::saved(function (Model $model): void {
            CapellCore::removeCacheKey(
                CacheEnum::modelDefaultKey($model::class),
            );
        });

        static::deleted(function (Model $model): void {
            CapellCore::removeCacheKey(
                CacheEnum::modelDefaultKey($model::class),
            );
        });
    }

    /**
     * @param  Builder<TModel>  $query
     */
    protected function scopeDefault(Builder $query, bool $default = true): void
    {
        $query->where($query->getModel()->qualifyColumn('default'), $default);
    }

    /**
     * @param  Builder<TModel>  $query
     */
    protected function scopeNonDefault(Builder $query): void
    {
        $query->default(false);
    }
}
