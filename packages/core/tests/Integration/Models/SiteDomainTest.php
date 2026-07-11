<?php

declare(strict_types=1);

// tests/Integration/Models/SiteDomainTest.php

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Facades\Event;

it('belongs to a site', function (): void {
    $site = Site::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne(['site_id' => $site->id]);

    expect($siteDomain->site)->toBeInstanceOf(Site::class)
        ->and($siteDomain->site->id)->toBe($site->id);
});

it('belongs to a language', function (): void {
    $language = Language::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne(['language_id' => $language->id]);

    expect($siteDomain->language)->toBeInstanceOf(Language::class)
        ->and($siteDomain->language->id)->toBe($language->id);
});

it('has many page urls', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $siteDomain = SiteDomain::factory()->createOne(['site_id' => $site->id, 'language_id' => $language->id]);
    $url = PageUrl::factory()->createOne(['site_id' => $site->id, 'language_id' => $language->id]);

    expect($siteDomain->pageUrls->pluck('id'))->toContain($url->id);
});

it('has a name attribute', function (): void {
    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com', 'path' => '/test']);

    expect($siteDomain->name)->toBe('example.com/test');
});

it('uses app host for name when domain is path only', function (): void {
    config(['app.url' => 'https://capell.test']);

    $siteDomain = SiteDomain::factory()->createOne(['domain' => null, 'path' => '/test']);

    expect($siteDomain->name)->toBe('capell.test/test');
});

it('has a url attribute', function (): void {
    $siteDomain = SiteDomain::factory()->createOne(['path' => '/test']);

    expect($siteDomain->url)->toBe('/test');
});

it('has a full url attribute', function (): void {
    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com', 'path' => '/test', 'scheme' => 'https']);

    expect($siteDomain->fullUrl)->toBe('https://example.com/test');
});

it('falls back to the request scheme when no scheme is configured', function (): void {
    config(['capell-frontend.default_scheme' => null]);
    request()->server->set('HTTPS', 'off');

    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com', 'path' => '/test', 'scheme' => null]);

    expect($siteDomain->fullUrl)->toBe('http://example.com/test');
});

it('uses app host for full urls when domain is path only', function (): void {
    config(['app.url' => 'https://capell.test']);

    $siteDomain = SiteDomain::factory()->createOne(['domain' => null, 'path' => '/test', 'scheme' => 'https']);

    expect($siteDomain->fullUrl)->toBe('https://capell.test/test');
});

it('builds a domain key for path only domains', function (): void {
    config(['app.url' => 'https://capell.test']);

    $siteDomain = SiteDomain::factory()->createOne(['domain' => null, 'path' => '/test', 'scheme' => 'https']);

    expect($siteDomain->getDomainKey())->toBe('https-capell-test-test');
});

it('invalidates frontend surrogate keys when the domain changes', function (): void {
    $siteDomain = SiteDomain::factory()->createOne(['domain' => 'example.com']);
    Event::fake([FrontendSurrogateKeysInvalidated::class]);

    $siteDomain->update(['domain' => 'next.example.com']);

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === ['site-' . $siteDomain->site_id],
    );
});
