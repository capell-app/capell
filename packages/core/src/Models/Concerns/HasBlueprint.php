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

    public function getBlueprint(): Blueprint
    {
        /** @var Blueprint $blueprint */
        $blueprint = $this->getRelationValue('blueprint') ?? $this->blueprint;

        return $blueprint;
    }
}
