<?php

declare(strict_types=1);

namespace Capell\Core\Models\Contracts;

use Capell\Core\Models\Blueprint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-require-extends Model
 */
interface Blueprintable
{
    /**
     * @return BelongsTo<Blueprint, $this>
     */
    public function blueprint(): BelongsTo;

    public function getBlueprint(): Blueprint;
}
