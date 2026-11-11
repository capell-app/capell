<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Enums\PropertyRequirement;
use Capell\Core\Enums\PropertyType;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyDefinition>
 */
class PropertyDefinitionFactory extends Factory
{
    protected $model = PropertyDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_set_id' => PropertySet::factory(),
            'key' => $this->faker->unique()->word(),
            'type' => PropertyType::Text,
            'semantic' => null,
            'requirement' => PropertyRequirement::None,
            'agent_visible' => true,
            'localised' => false,
            'multiple' => false,
            'locked' => false,
            'description' => $this->faker->sentence(),
            'unit_config' => null,
            'constraints' => null,
            'position' => 0,
        ];
    }

    /**
     * Give the definition declarative validation rules, in their stored
     * (snake_case) shape, e.g. `['max_length' => 20, 'unique_per_site' => true]`.
     *
     * @param  array<string, mixed>  $constraints
     */
    public function withConstraints(array $constraints): static
    {
        return $this->state(fn (): array => [
            'constraints' => PropertyConstraintsData::fromStored($constraints),
        ]);
    }
}
