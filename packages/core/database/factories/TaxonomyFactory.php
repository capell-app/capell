<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Taxonomy>
 */
class TaxonomyFactory extends Factory
{
    protected $model = Taxonomy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'key' => $this->faker->unique()->slug(1),
            'name' => $this->faker->words(2, true),
            'hierarchical' => false,
            'property_set_id' => null,
            'position' => 0,
        ];
    }
}
