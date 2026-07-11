<?php

declare(strict_types=1);

namespace Capell\Core\Models\Concerns;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Contracts\Blueprintable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin Model
 *
 * @phpstan-require-implements Blueprintable
 */
trait HasBlueprint
{
    /**
     * @return BelongsTo<Blueprint, $this>
     */
    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(Blueprint::class);
    }

    /**
     * @return BelongsTo<Blueprint, $this>
     */
    public function type(): BelongsTo
    {
        return $this->blueprint();
    }

    public function getBlueprint(): Blueprint
    {
        /** @var Blueprint $blueprint */
        $blueprint = $this->getRelationValue('blueprint') ?? $this->blueprint;

        return $blueprint;
    }

    protected function getTypeIdAttribute(): ?int
    {
        $value = $this->getAttribute('blueprint_id');

        return is_numeric($value) ? (int) $value : null;
    }

    protected function setTypeIdAttribute(mixed $value): void
    {
        $this->attributes['blueprint_id'] = $value;
    }
}
