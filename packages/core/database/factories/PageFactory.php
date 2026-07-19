<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Database\Factories\Concerns\HasAdmin;
use Capell\Core\Database\Factories\Concerns\HasFactoryPublishDates;
use Capell\Core\Database\Factories\Concerns\HasMeta;
use Capell\Core\Database\Factories\Concerns\HasTranslations;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Creator\BlueprintCreator;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    use HasAdmin;
    use HasFactoryPublishDates;
    use HasMeta;
    use HasTranslations;

    protected $model = Page::class;

    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|array<string, mixed>  $attributes
     */
    #[Override]
    public function create($attributes = [], ?Model $parent = null): mixed
    {
        return PublicationDateGuard::allow(
            fn (): mixed => parent::create($attributes, $parent),
        );
    }

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'uuid' => fn (): string => Str::uuid()->toString(),
            'name' => fn (): string => ucfirst(implode(' ', [
                $this->faker->word(),
                $this->faker->word(),
                $this->faker->word(),
            ])),
            'layout_id' => Layout::factory(),
            'blueprint_id' => function (): int {
                $blueprint = Blueprint::query()->pageType()->default()->first();

                if ($blueprint instanceof Blueprint) {
                    return $blueprint->id;
                }

                return Blueprint::factory()->page()->default()->create()->id;
            },
            'site_id' => Site::factory()->withTranslations(),
            // 'visible_from' => $this->faker->boolean() ? $this->faker->dateTimeBetween($created_at, '2 years') : null,
            'created_at' => fn () => $this->faker->dateTimeBetween('-1 year', '-6 month'),
            'updated_at' => fn (array $attributes) => $this->faker->dateTimeBetween($attributes['created_at']),
        ];
    }

    public function home(): static
    {
        return $this->state(function (): array {
            $type = Blueprint::query()->pageType()->where('key', 'home')->first();
            if (! $type instanceof Blueprint) {
                $type = resolve(BlueprintCreator::class)->homePageType();
            }

            return [
                'layout_id' => Layout::factory()->state(['key' => 'home']),
                'blueprint_id' => $type->id,
                'name' => 'Home',
                'parent_id' => null,
                'order' => 1,
            ];
        });
    }

    public function layout(Layout $layout): static
    {
        return $this->set('layout_id', $layout->id);
    }

    public function parent(Page $parent): static
    {
        return $this->state(fn (): array => [
            'parent_id' => $parent->getKey(),
            'site_id' => $parent->site_id,
        ]);
    }

    public function site(int|Site $site): static
    {
        return $this->set('site_id', $site instanceof Site ? $site->id : $site);
    }

    public function type(Blueprint $type): static
    {
        return $this->set('blueprint_id', $type->id);
    }

    public function children(int $count = 1): static
    {
        return $this->afterCreating(function (Page $page) use ($count): void {
            Page::factory()
                ->count($count)
                ->site($page->site)
                ->parent($page)
                ->withTranslations()
                ->create();
        });
    }

    /**
     * @param  Pageable<Page>  $page
     */
    public function canonicalPage(Pageable $page): self
    {
        return $this->meta([
            'canonical_pageable_type' => $page->getMorphClass(),
            'canonical_pageable_id' => $page->getKey(),
        ]);
    }
}
