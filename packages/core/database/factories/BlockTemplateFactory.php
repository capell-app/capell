<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\BlockTemplate;
use Capell\Core\Support\Slug\SlugGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockTemplate>
 */
class BlockTemplateFactory extends Factory
{
    protected $model = BlockTemplate::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $words = $this->faker->unique()->words(2, true);
        $name = is_array($words) ? implode(' ', $words) : $words;

        return [
            'key' => SlugGenerator::slug($name),
            'name' => str($name)->title()->toString(),
            'description' => $this->faker->sentence(),
            'blocks' => [
                ['type' => 'content', 'data' => ['content' => '']],
            ],
            'enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->set('enabled', false);
    }
}
