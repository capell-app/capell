<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Database\Factories\Concerns\HasAdmin;
use Capell\Core\Database\Factories\Concerns\HasMeta;
use Capell\Core\Database\Factories\Concerns\HasStatus;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    use HasAdmin;
    use HasMeta;
    use HasStatus;

    protected $model = Site::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'theme_id' => Theme::factory(),
            'language_id' => Language::factory(),
            'blueprint_id' => Blueprint::factory()->site(),
            'default' => fn (): bool => ! Site::query()->default()->exists(),
            'status' => true,
            'created_at' => $this->faker->dateTimeBetween('-1 year', '-6 month'),
            'updated_at' => $this->faker->dateTimeBetween('-5 month'),
        ];
    }

    public function default(): static
    {
        return $this->set('default', true);
    }

    public function deleted(): static
    {
        return $this->set('deleted_at', $this->faker->dateTimeBetween('-5 month'));
    }

    public function theme(Theme $theme): static
    {
        return $this->set('theme_id', $theme->id);
    }

    public function type(Blueprint $type): static
    {
        return $this->set('blueprint_id', $type->id);
    }

    /**
     * @param  array<int, Language>|Collection<int, Language>|Language|null  $languages
     * @param  array<int|string, mixed>  $data
     * @param  array<string, mixed>  $siteDomainData
     */
    public function withTranslations(null|array|Collection|Language $languages = null, array $data = [], array $siteDomainData = []): static
    {
        return $this->afterCreating(function (Site $site) use ($languages, $data, $siteDomainData): void {
            $site->loadMissing(['language', 'siteDomains']);

            if ($languages instanceof Language) {
                $languages = collect([$languages]);
            } elseif (is_array($languages)) {
                $languages = collect($languages);
            } elseif (is_null($languages)) {
                $languages = collect([$site->language]);
            }

            if ($languages->pluck('id')->doesntContain($site->language->id)) {
                $languages->push($site->language);
            }

            $languages->each(function (Language $language) use ($site, $data, $siteDomainData): void {
                $translation = Translation::factory()
                    ->make([
                        'language_id' => $language->id,
                        'translatable_id' => $site->id,
                        'translatable_type' => resolve(Site::class)->getMorphClass(),
                        ...$data,
                    ]);

                $site->translations()->updateOrCreate(
                    ['language_id' => $language->id],
                    $translation->only($translation->getFillable()),
                );

                if ($site->siteDomains->doesntContain('language_id', $language->id)) {
                    $site->siteDomains()->save(
                        SiteDomain::factory()
                            ->state([
                                'language_id' => $language->id,
                                'site_id' => $site->id,
                                ...($siteDomainData !== [] ? $siteDomainData : ['default' => $site->siteDomains->isEmpty()]),
                            ])
                            ->make(),
                    );
                }
            });

            $site->load('siteDomains');
        });
    }

    public function language(null|int|Language $language): self
    {
        if ($language === null) {
            return $this;
        }

        return $this->set('language_id', $language instanceof Language ? $language->id : $language);
    }
}
