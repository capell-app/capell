<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\PropertySet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertySet>
 */
class PropertySetFactory extends Factory
{
    protected $model = PropertySet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'version' => 1,
            'owner_package' => null,
        ];
    }
}
