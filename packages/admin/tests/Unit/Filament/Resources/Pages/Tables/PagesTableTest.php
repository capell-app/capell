<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Tables\PagesTable;
use Capell\Core\Actions\Redirects\CreateAutomaticRedirectAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

it('uses the canonical page url for the unfiltered url column when a redirect loads first', function (): void {
    $site = Site::factory()
        ->withTranslations(siteDomainData: [
            'scheme' => 'https',
            'domain' => 'example.test',
            'path' => null,
        ])
        ->createOne();
    $page = Page::factory()
        ->site($site)
        ->withTranslations()
        ->createOne();
    $canonical = $page->pageUrls()
        ->whereNull('type')
        ->where('language_id', $site->language_id)
        ->firstOrFail();

    $canonical->update(['url' => '/current-table-page']);

    expect(CreateAutomaticRedirectAction::run(
        $page,
        $page->site->language,
        '/old-table-page',
        '/current-table-page',
    ))->toBeTrue();

    $redirect = PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->where('url', '/old-table-page')
        ->firstOrFail();

    $page
        ->setRelation('pageUrls', new Collection([$redirect, $canonical]))
        ->setRelation('pageUrl', $canonical);

    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('getTableFilterState')->with('filter')->andReturn([]);

    $method = new ReflectionMethod(PagesTable::class, 'getUrlColumnState');
    $html = $method->invoke(null, $page, $livewire);

    expect($html)->toBeInstanceOf(HtmlString::class);
    assert($html instanceof HtmlString);

    expect($html->toHtml())
        ->toContain('/current-table-page')
        ->not->toContain('/old-table-page');
});

it('returns no url state when a page has no canonical page url', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->delete();

    expect($page->fresh()?->pageUrl)->toBeNull();

    $livewire = Mockery::mock(HasTable::class);
    $livewire->shouldReceive('getTableFilterState')->with('filter')->andReturn([]);

    $method = new ReflectionMethod(PagesTable::class, 'getUrlColumnState');
    $html = $method->invoke(null, $page, $livewire);

    expect($html)->toBeNull();
});
