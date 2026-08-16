<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Tables\Columns\Page\PageSummaryColumn;
use Capell\Core\Actions\Redirects\CreateAutomaticRedirectAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

it('uses the canonical page url when a superseded url is loaded first', function (): void {
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

    $canonical->update(['url' => '/current-summary-page']);

    expect(CreateAutomaticRedirectAction::run(
        $page,
        $page->site->language,
        '/old-summary-page',
        '/current-summary-page',
    ))->toBeTrue();

    $redirect = PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->where('url', '/old-summary-page')
        ->firstOrFail();

    $page
        ->setRelation('pageUrls', new Collection([$redirect, $canonical]))
        ->setRelation('pageUrl', $canonical);

    $html = PageSummaryColumn::make('name')
        ->record($page)
        ->formatState($page->name);

    expect($html)->toBeInstanceOf(HtmlString::class);
    assert($html instanceof HtmlString);

    expect($html->toHtml())
        ->toContain('href="https://example.test/current-summary-page"')
        ->not->toContain('/old-summary-page');
});

it('renders missing-url health when a page has no canonical page url', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    PageUrl::query()
        ->where('pageable_type', $page->getMorphClass())
        ->where('pageable_id', $page->getKey())
        ->delete();

    expect($page->fresh()?->pageUrl)->toBeNull();

    $html = PageSummaryColumn::make('name')
        ->record($page)
        ->formatState($page->name);

    expect($html)->toBeInstanceOf(HtmlString::class);
    assert($html instanceof HtmlString);

    expect($html->toHtml())
        ->toContain(__('capell-admin::table.page_health_missing_url'))
        ->not->toContain('<a href=');
});
