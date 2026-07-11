<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Database\Factories\Concerns\HasAdmin;
use Capell\Core\Database\Factories\Concerns\HasMeta;
use Capell\Core\Enums\LayoutGroupEnum;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Layout>
 */
class LayoutFactory extends Factory
{
    use HasAdmin;
    use HasMeta;

    protected $model = Layout::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'key' => str($name)->slug()->__toString(),
            'group' => LayoutGroupEnum::Default->value,
            'default' => fn (): bool => Layout::query()->count() === 0,
            'created_at' => $this->faker->dateTimeBetween('-1 year', '-6 month'),
            'updated_at' => $this->faker->dateTimeBetween('-5 month'),
        ];
    }

    public function default(): self
    {
        return $this->set('key', config('capell-frontend.default_layout', 'default'));
    }

    public function site(Site $site): static
    {
        return $this->set('site_id', $site->getKey());
    }
}
