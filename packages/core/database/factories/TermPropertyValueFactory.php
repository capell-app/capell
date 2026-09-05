<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\Term;
use Capell\Core\Models\TermPropertyValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermPropertyValue>
 */
class TermPropertyValueFactory extends Factory
{
    protected $model = TermPropertyValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'property_definition_id' => PropertyDefinition::factory(),
            'position' => 0,
            'value_text' => $this->faker->word(),
            'value_number' => null,
            'value_boolean' => null,
            'value_datetime' => null,
            'currency' => null,
            'unit' => null,
            'referenced_term_id' => null,
            'referenced_page_id' => null,
            'media_id' => null,
        ];
    }
}
