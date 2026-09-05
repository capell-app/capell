<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\BlueprintPropertySet;
use Capell\Core\Models\PropertySet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlueprintPropertySet>
 */
class BlueprintPropertySetFactory extends Factory
{
    protected $model = BlueprintPropertySet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'blueprint_id' => Blueprint::factory(),
            'property_set_id' => PropertySet::factory(),
            'overrides' => null,
        ];
    }
}
