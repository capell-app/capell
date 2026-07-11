<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Factory<Model>
 */
trait HasFactoryPublishDates
{
    public function published(?CarbonImmutable $visibleFrom = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'visible_from' => $visibleFrom ?? fake()->dateTimeBetween('-1 year', '-1 day'),
            'visible_until' => fake()->boolean() ? null : fake()->dateTimeBetween('+1 day', '+1 year'),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visible_from' => fake()->dateTimeBetween('+1 day', '+1 year'),
            'visible_until' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visible_from' => fake()->dateTimeBetween('-1 year', '-6 month'),
            'visible_until' => fake()->dateTimeBetween('-5 month', '-1 day'),
        ]);
    }
}
