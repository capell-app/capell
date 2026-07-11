<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Enums\AssetEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AssetAttachment>
 */
class AssetAttachmentFactory extends Factory
{
    protected $model = AssetAttachment::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'related_type' => 'page',
            'related_id' => Page::factory(),
            'asset_type' => 'page',
            'asset_id' => Page::factory(),
            'order' => $this->faker->randomNumber(1),
            'created_at' => $this->faker->dateTimeBetween('-1 year', '-6 month'),
            'updated_at' => $this->faker->dateTimeBetween('-5 month'),
        ];
    }

    public function related(Model $relation): static
    {
        return $this->state([
            'related_type' => $relation->getMorphClass(),
            'related_id' => $relation->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function asset(AssetEnum $type, array $state = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'asset_type' => $type->value,
            'asset_id' => match ($type) {
                AssetEnum::Page => Page::factory($state),
            },
        ]);
    }
}
