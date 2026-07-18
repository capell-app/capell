<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Database\Factories\Concerns\HasContent;
use Capell\Core\Database\Factories\Concerns\HasMeta;
use Capell\Core\Models\Language;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Slug\SlugGenerator;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Stringable;

/**
 * @extends Factory<Translation>
 */
class TranslationFactory extends Factory
{
    use HasContent;
    use HasMeta;

    protected $model = Translation::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        $created_at = $this->faker->dateTimeBetween('-1 year', '-6 month');

        $title = $this->faker->catchPhrase();

        return [
            'title' => $title,
            'language_id' => Language::factory(),
            'content' => $this->htmlContent(),
            'created_at' => $created_at,
            'updated_at' => $this->faker->dateTimeBetween($created_at),
        ];
    }

    public function translatable(Model $relation): static
    {
        return $this->state([
            'translatable_type' => $relation->getMorphClass(),
            'translatable_id' => $relation->getKey(),
        ]);
    }

    public function language(Language $language): self
    {
        return $this->set('language_id', $language->id);
    }

    public function slug(null|string|Stringable $slug = null): self
    {
        return $this->state(function (array $attributes) use ($slug): array {
            $meta = $attributes['meta'] ?? [];

            if ($meta instanceof Closure) {
                $meta = $meta($attributes);
            }

            if (isset($meta['slug'])) {
                return ['meta' => $meta];
            }

            if ($slug === null) {
                $slug = SlugGenerator::slug((string) ($attributes['title'] ?? $this->faker->catchPhrase()));
            }

            if ($slug !== '/') {
                $slug = trim((string) $slug, '/');
            }

            $meta['slug'] = $slug;

            return ['meta' => $meta];
        });
    }
}
