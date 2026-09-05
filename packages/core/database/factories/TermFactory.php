<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Term>
 */
class TermFactory extends Factory
{
    protected $model = Term::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'taxonomy_id' => Taxonomy::factory(),
            'parent_id' => null,
            'slug' => $this->faker->unique()->slug(1),
            'name' => $this->faker->words(2, true),
            'semantic' => null,
            'position' => 0,
        ];
    }
}
