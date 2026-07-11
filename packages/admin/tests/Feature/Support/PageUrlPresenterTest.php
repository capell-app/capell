<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\HtmlString;

it('returns null instead of throwing when a page url has no active site domain', function (): void {
    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com', 'path' => null, 'scheme' => 'https']);

    $pageUrl = PageUrl::factory()
        ->recycle($siteDomain->site)
        ->recycle($siteDomain->language)
        ->create(['url' => '/test']);

    $siteDomain->delete();
    $pageUrl->unsetRelation('siteDomain');

    expect(PageUrlPresenter::fullUrl($pageUrl))->toBeNull()
        ->and(PageUrlPresenter::displayUrl($pageUrl))->toBe('/test');
});

it('uses the full page url as the pages table url column label', function (): void {
    $language = Language::factory()->english()->create();
    $site = Site::factory()->for($language, 'language')->create();
    $siteDomain = SiteDomain::factory()
        ->site($site)
        ->language($language)
        ->create([
            'domain' => 'example.test',
            'path' => null,
            'scheme' => 'https',
        ]);
    $page = Page::factory()->site($site)->create();
    $pageUrl = PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->create(['url' => '/about']);

    $page->setRelation('pageUrls', collect([$pageUrl->setRelation('siteDomain', $siteDomain)]));

    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('getTableFilterState')->with('filter')->andReturn([]);

    $method = new ReflectionMethod(PagesTable::class, 'getUrlColumnState');

    $state = $method->invoke(null, $page, $livewire);

    expect($state)->toBeInstanceOf(HtmlString::class)
        ->and($state->toHtml())->toContain('>https://example.test/about</a>');
});
