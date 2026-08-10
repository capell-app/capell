<?php

declare(strict_types=1);

// tests/Integration/Models/SiteDomainTest.php

use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Language;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Database\QueryException;
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

it('persists non-default ports in full urls and domain keys', function (): void {
    $siteDomain = SiteDomain::factory()->createOne([
        'domain' => 'localhost',
        'path' => '/test',
        'port' => 8081,
        'scheme' => 'http',
    ]);

    expect($siteDomain->port)->toBe(8081)
        ->and($siteDomain->fullUrl)->toBe('http://localhost:8081/test')
        ->and($siteDomain->getDomainKey())->toBe('http-localhost-8081-test')
        ->and($siteDomain->routing_identity)->toBeString()->toHaveLength(64);
});

it('normalizes explicit default ports to null', function (): void {
    $siteDomain = SiteDomain::factory()->createOne([
        'domain' => 'example.test',
        'port' => 443,
        'scheme' => 'https',
    ]);

    expect($siteDomain->port)->toBeNull()
        ->and($siteDomain->root_url)->toBe('https://example.test');
});

it('rejects invalid ports', function (): void {
    SiteDomain::factory()->createOne(['port' => 65536]);
})->throws(InvalidArgumentException::class);

it('enforces active routing identities while allowing soft-deleted history', function (): void {
    $attributes = [
        'domain' => 'unique.example.test',
        'path' => '/en',
        'port' => 8081,
        'scheme' => 'http',
    ];

    $first = SiteDomain::factory()->createOne($attributes);

    expect(fn (): SiteDomain => SiteDomain::factory()->createOne($attributes))
        ->toThrow(QueryException::class);

    $first->delete();

    $replacement = SiteDomain::factory()->createOne($attributes);

    expect($first->fresh()->routing_identity)->toBeNull()
        ->and($replacement->routing_identity)->toBeString();
});

it('rejects normalized origin collisions even when the first domain is disabled', function (): void {
    $attributes = [
        'domain' => 'Example.test.',
        'path' => '/docs/',
        'scheme' => 'HTTPS',
        'status' => false,
    ];

    SiteDomain::factory()->createOne($attributes);

    expect(fn (): SiteDomain => SiteDomain::factory()->createOne([
        ...$attributes,
        'domain' => 'example.test',
        'path' => 'docs',
        'scheme' => 'https',
    ]))->toThrow(QueryException::class);
});

it('normalizes default ports and IPv6 hosts into one routing identity', function (): void {
    $attributes = [
        'domain' => '[2001:DB8::1]',
        'path' => '/',
        'scheme' => 'HTTPS',
        'port' => 443,
    ];

    SiteDomain::factory()->createOne($attributes);

    expect(fn (): SiteDomain => SiteDomain::factory()->createOne([
        ...$attributes,
        'domain' => '2001:db8::1',
        'port' => null,
    ]))->toThrow(QueryException::class);
});

it('allows distinct primary and mounted aliases to share an origin host', function (): void {
    $primary = SiteDomain::factory()->createOne([
        'domain' => 'example.test',
        'path' => '/',
        'scheme' => 'https',
        'port' => null,
        'default' => true,
    ]);
    $alias = SiteDomain::factory()->createOne([
        'domain' => 'example.test',
        'path' => '/fr',
        'scheme' => 'https',
        'port' => 443,
        'default' => false,
    ]);

    expect($primary->routing_identity)
        ->not->toBe($alias->routing_identity)
        ->and($alias->port)->toBeNull();
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
