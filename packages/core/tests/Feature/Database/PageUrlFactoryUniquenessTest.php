<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Faker\Generator;
use Faker\Provider\Base;

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
