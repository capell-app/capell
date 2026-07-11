<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;

beforeEach(function (): void {
    app()->instance('capell.frontend.resource-debug-overlay-payload', fn (Page $page): array => [
        'summary' => [
            'cssAssets' => 1,
            'jsAssets' => 0,
            'cssRawBytes' => 256,
            'cssGzipBytes' => 128,
            'jsRawBytes' => 0,
            'jsGzipBytes' => 0,
            'budgetPasses' => true,
        ],
        'budgetFailures' => [],
        'conflicts' => [],
        'assets' => [
            [
                'source' => 'resources/css/gallery.css',
                'page' => $page->getKey(),
            ],
        ],
    ]);
});

it('returns frontend resource debug overlay payload to authenticated admins by page id', function (): void {
    test()->actingAsAdmin();

    $page = Page::factory()->createOne();

    test()->getJson(route('capell-admin.api.frontend-resource-debug-overlay', [
        'page_id' => $page->getKey(),
    ]))
        ->assertOk()
        ->assertJsonStructure([
            'summary' => [
                'cssAssets',
                'jsAssets',
                'cssRawBytes',
                'cssGzipBytes',
                'jsRawBytes',
                'jsGzipBytes',
                'budgetPasses',
            ],
            'budgetFailures',
            'conflicts',
            'assets',
        ]);
});

it('resolves frontend resource debug overlay payload by public url', function (): void {
    test()->actingAsAdmin();

    $site = Site::factory()
        ->withTranslations(siteDomainData: ['domain' => 'example.test', 'scheme' => 'https', 'path' => null])
        ->createOne();
    $page = Page::factory()->site($site)->createOne();
    PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($site->language)
        ->createOne(['url' => '/diagnostic-page']);

    test()->getJson(route('capell-admin.api.frontend-resource-debug-overlay', [
        'url' => 'https://example.test/diagnostic-page',
    ]))
        ->assertOk()
        ->assertJsonPath('summary.budgetPasses', true);
});

it('scopes frontend resource debug overlay url resolution by site domain', function (): void {
    test()->actingAsAdmin();

    $firstSite = Site::factory()
        ->withTranslations(siteDomainData: ['domain' => 'first.test', 'scheme' => 'https', 'path' => null])
        ->createOne();
    $secondSite = Site::factory()
        ->withTranslations(siteDomainData: ['domain' => 'second.test', 'scheme' => 'https', 'path' => null])
        ->createOne();
    $firstPage = Page::factory()->site($firstSite)->createOne();
    $secondPage = Page::factory()->site($secondSite)->createOne();

    PageUrl::factory()
        ->page($firstPage)
        ->site($firstSite)
        ->language($firstSite->language)
        ->createOne(['url' => '/shared']);
    PageUrl::factory()
        ->page($secondPage)
        ->site($secondSite)
        ->language($secondSite->language)
        ->createOne(['url' => '/shared']);

    test()->getJson(route('capell-admin.api.frontend-resource-debug-overlay', [
        'url' => 'https://second.test/shared',
    ]))
        ->assertOk()
        ->assertJsonPath('assets.0.page', $secondPage->getKey());
});

it('requires authentication for frontend resource debug overlay payloads', function (): void {
    test()->getJson(route('capell-admin.api.frontend-resource-debug-overlay', [
        'page_id' => Page::factory()->createOne()->getKey(),
    ]))
        ->assertUnauthorized();
});

it('serves the authenticated post-load frontend resource debug overlay script', function (): void {
    test()->actingAsAdmin();

    test()->get(route('capell-admin.api.frontend-resource-debug-overlay-script'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
        ->assertSee('capell-frontend-resource-debug-overlay', false)
        ->assertSee('frontend-resource-debug-overlay', false)
        ->assertDontSee('page_id', false);
});

it('requires authentication for the frontend resource debug overlay script', function (): void {
    test()->getJson(route('capell-admin.api.frontend-resource-debug-overlay-script'))
        ->assertUnauthorized();
});
