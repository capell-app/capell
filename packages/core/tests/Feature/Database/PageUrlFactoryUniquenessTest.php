<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use Faker\Provider\Base;

/**
 * Factories take the container's cached per-locale Generator, so pinning it
 * would leak into every later test in the worker. Each test gets a fresh
 * Generator instead, and the original is put back afterwards.
 */
beforeEach(function (): void {
    $this->originalFaker = resolve(Generator::class);
    app()->instance(Generator::class, FakerFactory::create(config('app.faker_locale', 'en_US')));
});

afterEach(function (): void {
    app()->instance(Generator::class, $this->originalFaker);
});

/**
 * Pins every random draw the factories use for URLs, so the repeat that
 * otherwise happens once in many thousand CI runs happens every time.
 */
function pinFactoryRandomWords(): void
{
    resolve(Generator::class)->addProvider(new class(resolve(Generator::class)) extends Base
    {
        public function word(): string
        {
            return 'pinned';
        }
    });
}

function pinFactoryRandomSlugs(): void
{
    resolve(Generator::class)->addProvider(new class(resolve(Generator::class)) extends Base
    {
        public function slug(int $nbWords = 6, bool $variableNbWords = true): string
        {
            return 'pinned-slug';
        }
    });
}

it('never draws the same canonical URL for two pages of one site', function (): void {
    pinFactoryRandomWords();
    $site = Site::factory()->withTranslations()->create();

    $first = Page::factory()->site($site)->withTranslations()->create();
    $second = Page::factory()->site($site)->withTranslations()->create();

    expect($first->name)->not->toBe($second->name);
    expect(PageUrl::query()->where('site_id', $site->id)->whereNull('type')->distinct()->count('url'))
        ->toBe(PageUrl::query()->where('site_id', $site->id)->whereNull('type')->count());
});

it('never draws the same URL for two url factory records', function (): void {
    $page = Page::factory()->withTranslations()->create();
    pinFactoryRandomSlugs();

    $urls = collect([
        PageUrl::factory()->site($page->site)->page($page)->language($page->site->language)->create(),
        PageUrl::factory()->site($page->site)->page($page)->language($page->site->language)->create(),
    ]);

    expect($urls->pluck('url')->unique())->toHaveCount(2);
});

it('leaves the shared factory generator unpinned', function (): void {
    pinFactoryRandomWords();
    pinFactoryRandomSlugs();
    $pinned = resolve(Generator::class);

    app()->instance(Generator::class, $this->originalFaker);

    expect($pinned->word())->toBe('pinned')
        ->and(resolve(Generator::class))->not->toBe($pinned)
        ->and(resolve(Generator::class)->word())->not->toBe('pinned')
        ->and(resolve(Generator::class)->slug())->not->toBe('pinned-slug');
});
