<?php

declare(strict_types=1);

namespace Capell\Tests\Fixtures\Activity;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

/**
 * A host-owned model with the methods required by both activity contracts.
 * The concrete test model implements the installed vendor's interface.
 */
class ActivityRecord extends Model
{
    use HasFactory;

    protected $table = 'custom_activity_log';

    protected $guarded = [];

    protected $casts = ['properties' => 'collection', 'attribute_changes' => 'collection'];

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    public function getProperty(string $propertyName, mixed $defaultValue = null): mixed
    {
        return data_get($this->getAttribute('properties'), $propertyName, $defaultValue);
    }

    public function getExtraProperty(string $propertyName, mixed $defaultValue): mixed
    {
        return $this->getProperty($propertyName, $defaultValue);
    }

    /** @return Collection<array-key, mixed> */
    public function changes(): Collection
    {
        $properties = $this->getAttribute('properties');

        return $properties instanceof Collection ? $properties->only(['old', 'attributes']) : new Collection;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeInLog(Builder $query, mixed ...$logNames): Builder
    {
        return $query->whereIn('log_name', $logNames);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeCausedBy(Builder $query, Model $causer): Builder
    {
        return $query->where('causer_type', $causer->getMorphClass())->where('causer_id', $causer->getKey());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey());
    }
}
