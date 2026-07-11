<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories\Concerns;

use Capell\Core\Models\Concerns\HasStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @mixin Factory<HasStatus>
 */
trait HasMeta
{
    use HasMetaState;

    /**
     * @param  array<string, mixed>|string  $meta
     */
    public function meta(array|string $meta, mixed $value = null): static
    {
        return $this->setMetaState('meta', $meta, $value);
    }
}
