<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageUrl>
 */
class UrlFactory extends Factory
{
    protected $model = PageUrl::class;

    /**
     * Enabled URLs are unique per site and language, and Faker slugs repeat
     * eventually, so every generated path starts with a process-wide sequence token.
     */
    private static int $urlSequence = 0;

    public function definition(): array
    {
        return [
            'url' => '/' . $this->faker->slug() . '/' . $this->uniqueSlug(),
            'language_id' => fn () => Language::query()->value('id') ?? Language::factory(),
            'site_id' => fn (array $attributes) => Site::factory(['language_id' => $attributes['language_id']])
                ->has(SiteDomain::factory()->state([
                    'default' => true,
                    'language_id' => $attributes['language_id'],
                ])),
            'pageable_type' => resolve(Page::class)->getMorphClass(),
            'pageable_id' => fn (array $attributes): PageFactory => Page::factory()->site($attributes['site_id']),
            'status' => true,
            'type' => null,
        ];
    }

    public function type(UrlTypeEnum $type): static
    {
        return $this->set('type', $type);
    }

    public function redirect(): static
    {
        return $this->state([
            'type' => UrlTypeEnum::Redirect,
            'url' => $this->faker->url() . '?n=' . ++self::$urlSequence,
        ]);
    }

    public function alias(): static
    {
        return $this->state([
            'type' => UrlTypeEnum::Alias,
            'url' => $this->uniqueSlug(),
        ]);
    }

    public function language(Language $language): static
    {
        return $this->set('language_id', $language);
    }

    /**
     * @param  Pageable<Page>  $page
     */
    public function page(Pageable $page): self
    {
        return $this->state([
            'pageable_type' => $page->getMorphClass(),
            'pageable_id' => $page->getKey(),
        ]);
    }

    public function manualRedirect(): static
    {
        return $this->state([
            'type' => UrlTypeEnum::Redirect,
            'is_manual' => true,
            'pageable_type' => null,
            'pageable_id' => null,
            'status_code' => 301,
            'url' => '/' . $this->uniqueSlug(),
            'target_url' => '/' . $this->uniqueSlug(),
        ]);
    }

    public function site(Site $site): static
    {
        return $this->set('site_id', $site);
    }

    private function uniqueSlug(): string
    {
        return 'u' . ++self::$urlSequence . '-' . $this->faker->slug();
    }
}
