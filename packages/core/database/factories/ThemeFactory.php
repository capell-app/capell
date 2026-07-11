<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Database\Factories\Concerns\HasAdmin;
use Capell\Core\Database\Factories\Concerns\HasMeta;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Theme>
 */
class ThemeFactory extends Factory
{
    use HasAdmin;
    use HasMeta;

    protected $model = Theme::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $name = $this->faker->word();

        return [
            'name' => $name,
            'key' => $this->faker->unique()->slug(),
            'blueprint_id' => fn () => Blueprint::factory()->theme(),
            'created_at' => $this->faker->dateTimeBetween('-1 year', '-6 month'),
            'updated_at' => $this->faker->dateTimeBetween('-5 month'),
        ];
    }

    public function default(): self
    {
        return $this->set('key', config('capell-frontend.foundation_theme', 'default'));
    }

    public function defaultMeta(): self
    {
        return $this->set(
            'meta',
            [
                'assets_path' => 'vendor/capell-frontend',
                'assets' => [
                    'resources/js/capell-frontend.js',
                ],
                'colors' => [
                    'primary' => $this->faker->rgbColor(),
                    'secondary' => $this->faker->rgbColor(),
                ],
                'font_family' => '',
                'footer' => true,
                'header' => true,
                'header_background_color' => null,
                'main_background_color' => null,
                'header_position' => 'sticky',
                'font_heading_family' => '',
                'link_color' => 'rgb(' . $this->faker->rgbColor() . ')',
                'rounded' => true,
            ],
        );
    }
}
