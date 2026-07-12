<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Database\Factories\Concerns\HasStatus;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteDomain>
 */
class SiteDomainFactory extends Factory
{
    use HasStatus;

    protected $model = SiteDomain::class;

    public function definition(): array
    {
        return [
            'domain' => $this->faker->domainName(),
            'scheme' => $this->faker->randomElement(['http', 'https']),
            'path' => $this->faker->optional()->domainWord(),
            'language_id' => Language::factory(),
            'site_id' => fn (array $attributes) => Site::factory()
                ->state([
                    'language_id' => $attributes['language_id'],
                ])
                ->hasTranslations(['language_id' => $attributes['language_id']]),
            'default' => null,
            'status' => true,
            'created_at' => $this->faker->dateTimeBetween('-1 year', '-6 month'),
            'updated_at' => $this->faker->dateTimeBetween('-5 month'),
        ];
    }

    public function default(): static
    {
        return $this->state([
            'default' => true,
            'domain' => 'localhost',
            'scheme' => 'http',
            'path' => null,
        ]);
    }

    public function language(Language $language): static
    {
        return $this->set('language_id', $language);
    }

    public function path(): static
    {
        return $this->set('path', $this->faker->domainWord());
    }

    public function site(Site $site): static
    {
        return $this->set('site_id', $site->id);
    }

    public function languagePath(Language $language): static
    {
        return $this->set('path', '/' . $language);
    }
}
