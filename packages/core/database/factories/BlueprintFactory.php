<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use BackedEnum;
use Capell\Core\Database\Factories\Concerns\HasAdmin;
use Capell\Core\Database\Factories\Concerns\HasMeta;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Blueprint>
 */
class BlueprintFactory extends Factory
{
    use HasAdmin;
    use HasMeta;

    protected $model = Blueprint::class;

    public function default(bool $default = true): static
    {
        return $this->set('default', $default);
    }

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $name = $this->faker->word();

        return [
            'name' => $name,
            'key' => $this->faker->unique()->slug(),
            'type' => $this->faker->randomElement(BlueprintSubjectEnum::cases()),
            'default' => false,
            'group' => null,
            'admin' => [
                'configurator' => 'Default',
            ],
            'created_at' => $this->faker->dateTimeBetween('-1 year', '-6 month'),
            'updated_at' => $this->faker->dateTimeBetween('-5 month'),
        ];
    }

    public function group(?string $string): static
    {
        return $this->set('group', $string);
    }

    public function page(): static
    {
        return $this->type(BlueprintSubjectEnum::Page);
    }

    public function site(): static
    {
        return $this->type(BlueprintSubjectEnum::Site);
    }

    public function theme(): static
    {
        return $this->type(BlueprintSubjectEnum::Theme);
    }

    public function navigation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => 'navigation',
        ]);
    }

    public function type(string|BackedEnum $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type instanceof BackedEnum ? $type->value : $type,
        ]);
    }

    public function adminTypeConfigurator(string $configurator): static
    {
        return $this->state(fn (array $attributes): array => [
            'admin' => array_merge(
                $attributes['admin'] ?? [],
                ['type_configurator' => $configurator],
            ),
        ]);
    }

    public function contentStructure(ContentStructure $contentStructure): static
    {
        return $this->state(fn (array $attributes): array => [
            'meta' => array_merge(
                $attributes['meta'] ?? [],
                ['content_structure' => $contentStructure],
            ),
        ]);
    }
}
