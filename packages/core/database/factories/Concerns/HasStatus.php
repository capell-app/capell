<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories\Concerns;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @mixin Factory<\Capell\Core\Models\Concerns\HasStatus>
 */
trait HasStatus
{
    public function status(bool $status): static
    {
        return $this->set('status', $status);
    }

    public function enabled(): static
    {
        return $this->status(true);
    }

    public function disabled(): static
    {
        return $this->status(false);
    }
}
