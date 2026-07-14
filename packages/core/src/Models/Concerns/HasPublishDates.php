<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Enums\PublishStatusEnum;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\Contracts\Publishable;
use Capell\Core\Support\Publishing\PublishSentinel;
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
     * The five-way visibility state (draft/scheduled/published/expired/deleted)
     * derived from the record's dates. Unlike {@see self::isPending()} this
     * distinguishes the far-future draft sentinel from a genuine schedule.
     */
    public function publishVisibilityState(?CarbonImmutable $now = null): PublishVisibilityStateEnum
    {
        return PublishVisibilityStateEnum::fromDates(
            $this->normalizeDateAttribute('visible_from'),
            $this->normalizeDateAttribute('visible_until'),
            $this->trashed(),
            $now,
        );
    }

    /**
     * Whether `visible_from` holds the far-future draft sentinel rather than
     * a genuine publish/schedule date.
     */
    public function isDraftSentinel(): bool
    {
        return PublishSentinel::isDraftValue($this->normalizeDateAttribute('visible_from'));
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
     * Umbrella scope: any future `visible_from`, whether a genuine schedule or
     * the draft sentinel. Kept for backwards compatibility — use
     * {@see self::scopeScheduled()} / {@see self::scopeDraftSentinel()} when
     * the draft/scheduled distinction matters.
     *
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
     * Records whose `visible_from` is the far-future draft sentinel.
     *
     * @param  Builder<Model>  $builder
     */
    protected function scopeDraftSentinel(Builder $builder): void
    {
        $column = $builder->getModel()->qualifyColumn('visible_from');
        $builder->whereNotNull($column)->where($column, '>', PublishSentinel::draftBoundary());
    }

    /**
     * Records with a genuine future schedule: `visible_from` in the future but
     * on or before the draft-sentinel boundary.
     *
     * @param  Builder<Model>  $builder
     */
    protected function scopeScheduled(Builder $builder): void
    {
        $column = $builder->getModel()->qualifyColumn('visible_from');
        $now = CarbonImmutable::now();
        $builder
            ->whereNotNull($column)
            ->where($column, '>', $now)
            ->where($column, '<=', PublishSentinel::draftBoundary($now));
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
