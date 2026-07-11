<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Models\Contracts\Publishable;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-implements Publishable
 *
 * @method static Builder<static> publishedDate()
 *
 * @property CarbonImmutable|null $visible_from
 * @property CarbonImmutable|null $visible_until
 */
trait HasPublishDates
{
    public function isExpired(): bool
    {
        $publishTo = $this->normalizeDateAttribute('visible_until');

        return (bool) $publishTo?->isPast();
    }

    public function isPending(): bool
    {
        $publishFrom = $this->normalizeDateAttribute('visible_from');

        return (bool) $publishFrom?->isFuture();
    }

    public function getPublishStatus(): PublishStatusEnum
    {
        return $this->getPublishStatusAttribute();
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function scopeExpired(Builder $builder): void
    {
        $model = $builder->getModel();
        $column = $model->qualifyColumn('visible_until');
        $now = now();
        $builder->whereNotNull($column)->where($column, '<', $now);
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function scopePending(Builder $builder): void
    {
        $model = $builder->getModel();
        $column = $model->qualifyColumn('visible_from');
        $now = now();
        $builder->whereNotNull($column)->where($column, '>', $now);
    }

    /**
     * @param  Builder<Model>  $builder
     */
    protected function scopePublishedDate(Builder $builder): void
    {
        $now = now();
        $model = $builder->getModel();
        $builder->where(function (Builder $query) use ($model, $now): void {
            $column = $model->qualifyColumn('visible_from');
            $query->whereNull($column)->orWhere($column, '<=', $now);
        })->where(function (Builder $query) use ($model, $now): void {
            $column = $model->qualifyColumn('visible_until');
            $query->whereNull($column)->orWhere($column, '>=', $now);
        });
    }

    protected function getPublishStatusAttribute(): PublishStatusEnum
    {
        return PublishStatusEnum::fromModel($this);
    }

    /**
     * Normalize a date attribute to a CarbonImmutable instance if possible.
     */
    private function normalizeDateAttribute(string $attribute): ?CarbonImmutable
    {
        $value = $this->getAttribute($attribute);

        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) || is_int($value)) {
            // Accept common string or timestamp form-builder
            return CarbonImmutable::parse((string) $value);
        }

        return null;
    }
}
