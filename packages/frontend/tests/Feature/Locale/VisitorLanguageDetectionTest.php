<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Enums\VisitorLanguageDetection;
use Capell\Frontend\Http\Middleware\DetectVisitorLanguage;
use Capell\Frontend\Http\Middleware\RejectReservedFrontendPaths;
use Capell\Frontend\Settings\FrontendSettings;
use Capell\Frontend\Support\Locale\VisitorLanguageCookie;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Capell\Tests\Support\Concerns\TestingFrontend;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

uses(TestingFrontend::class)->group('frontend', 'locale');

/**
 * @return array{Site, Language, Language, PageUrl, PageUrl}
 */
function visitorLanguageFixture(string $primaryCode = 'en'): array
{
    $primary = $primaryCode === 'en'
        ? Language::factory()->english(isDefault: true)->createOne()
        : Language::factory()->createOne(['code' => $primaryCode, 'locale' => $primaryCode, 'default' => true]);

    $french = Language::factory()->french()->createOne();

    $site = Site::factory()
        ->withTranslations([$primary, $french])
        ->createOne(['language_id' => $primary->id]);

    $site->siteDomains->firstWhere('language_id', $primary->id)?->update([
        'domain' => 'primary.example.com',
        'scheme' => 'http',
        'path' => null,
        'default' => true,
        'status' => true,
    ]);

    $site->siteDomains->firstWhere('language_id', $french->id)?->update([
        'domain' => 'fr.example.com',
        'scheme' => 'http',
        'path' => null,
        'default' => false,
        'status' => true,
    ]);

    Page::factory()
        ->site($site)
        ->withTranslations([$primary, $french])
        ->create(['name' => 'Pricing']);

    // Site/language collections are cached in memory; the fixture mutated them.
    CapellCore::flushCache();

    $site->unsetRelation('siteDomains');
    $site->load('siteDomains');

    $primaryUrl = PageUrl::query()
        ->where('site_id', $site->id)
        ->where('language_id', $primary->id)
        ->whereNull('type')
        ->firstOrFail();

    $frenchUrl = PageUrl::query()
        ->where('site_id', $site->id)
        ->where('language_id', $french->id)
        ->whereNull('type')
        ->firstOrFail();

    return [$site, $primary, $french, $primaryUrl, $frenchUrl];
}

function visitorLanguageMode(VisitorLanguageDetection $mode): void
{
    app()->bind(FrontendSettingsReaderInterface::class, fn (): object => new readonly class($mode) implements FrontendSettingsReaderInterface
    {
        public function __construct(private VisitorLanguageDetection $mode) {}

        public function settings(): FrontendSettings
        {
            return new FrontendSettings;
        }

        public function minifyHtml(): bool
        {
            return false;
        }

        public function visitorLanguageDetection(): VisitorLanguageDetection
        {
            return $this->mode;
        }
    });
}

/** @param array<string, string> $headers */
function visitorLanguageRequest(string $url, array $headers = [], string $method = Request::METHOD_GET): Request
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
    }

    $parts = parse_url($url);
    $server['HTTP_HOST'] = $parts['host'] ?? 'primary.example.com';

    return Request::create($url, $method, server: $server);
}

function visitorLanguageRun(Request $request): Response
{
    return resolve(DetectVisitorLanguage::class)->handle(
        $request,
        fn (): Response => new Response('<html lang="en"></html>', Response::HTTP_OK, ['Content-Type' => 'text/html']),
    );
}

it('registers the detector after the reserved-request rejections and before web', function (): void {
    $stack = resolve(FrontendRouteMiddlewareRegistry::class)->all();

    $detector = array_search('frontend.language_detect', $stack, true);
    $web = array_search('web', $stack, true);

    expect($detector)->toBeInt();
    expect($web)->toBeInt();
    expect($detector)->toBeLessThan($web);
    expect($detector)->toBeGreaterThan(array_search(
        RejectReservedFrontendPaths::class,
        $stack,
        true,
    ));
});

it('is a complete no-op when detection is off', function (): void {
    [, , , $primaryUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Off);

    $response = visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'fr-FR,fr;q=0.9'],
    ));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect($response->headers->getCookies())->toBe([]);
    expect($response->headers->has('Vary'))->toBeFalse();
});

it('redirects a first-time visitor to the sibling page and preserves the query string', function (): void {
    [, , , $primaryUrl, $frenchUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    $response = visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url . '?plan=team&ref=news',
        ['Accept-Language' => 'fr-FR,fr;q=0.9'],
    ));

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->getStatusCode())->toBe(Response::HTTP_FOUND);
    expect($response->headers->get('Location'))
        ->toBe('http://fr.example.com' . $frenchUrl->url . '?plan=team&ref=news');
    expect($response->headers->get('Vary'))->toBe('Accept-Language');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $cookies = collect($response->headers->getCookies())
        ->keyBy(fn (Cookie $cookie): string => $cookie->getName());

    $preference = $cookies->get(VisitorLanguageCookie::NAME);

    expect($preference)->toBeInstanceOf(Cookie::class);
    expect($preference?->getValue())->toStartWith('fr');
    expect($preference?->isHttpOnly())->toBeFalse();
    expect($cookies->get(VisitorLanguageCookie::ORIGIN_NAME))->toBeInstanceOf(Cookie::class);
});

it('never redirects a visitor who already carries the preference cookie', function (): void {
    [, , , $primaryUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    $request = visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'fr-FR,fr;q=0.9'],
    );
    $request->cookies->set(VisitorLanguageCookie::NAME, 'en');

    expect(visitorLanguageRun($request)->getStatusCode())->toBe(Response::HTTP_OK);
});

it('serves a real crawler a 200 even when it sends an Accept-Language header', function (): void {
    [, , , $primaryUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    // None of these contain the substring "bot"; the old heuristic passed them
    // all straight through to a redirect.
    $agents = [
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'WhatsApp/2.23.20.0',
        'Mozilla/5.0 (compatible; Google-InspectionTool/1.0;)',
        'Mozilla/5.0 Chrome-Lighthouse',
    ];

    foreach ($agents as $agent) {
        $response = visitorLanguageRun(visitorLanguageRequest(
            'http://primary.example.com' . $primaryUrl->url,
            ['Accept-Language' => 'fr-FR,fr;q=0.9', 'User-Agent' => $agent],
        ));

        expect($response->getStatusCode())
            ->toBe(Response::HTTP_OK, $agent . ' should not be redirected');
    }
});

it('does not redirect when the page has no sibling in the preferred language', function (): void {
    [$site, , $french, $primaryUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    PageUrl::query()
        ->where('site_id', $site->id)
        ->where('language_id', $french->id)
        ->delete();

    expect(visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'fr-FR,fr;q=0.9'],
    ))->getStatusCode())->toBe(Response::HTTP_OK);
});

it('leaves a non-GET request completely untouched', function (): void {
    [, , , $primaryUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    $response = visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'fr-FR,fr;q=0.9'],
        Request::METHOD_POST,
    ));

    expect($response->getStatusCode())->toBe(Response::HTTP_OK);
    expect($response->headers->getCookies())->toBe([]);
});

it('does not redirect an in-site navigation', function (): void {
    [, , , $primaryUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    expect(visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'fr-FR,fr;q=0.9', 'Sec-Fetch-Site' => 'same-origin'],
    ))->getStatusCode())->toBe(Response::HTTP_OK);

    // Referer fallback for browsers that omit Sec-Fetch-Site.
    expect(visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'fr-FR,fr;q=0.9', 'Referer' => 'http://primary.example.com/somewhere'],
    ))->getStatusCode())->toBe(Response::HTTP_OK);
});

it('does not redirect when the current page language appears anywhere in Accept-Language', function (): void {
    [, , , $primaryUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    // English is last and lowest-weighted, but stating it at all means the
    // visitor reads it.
    expect(visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.1'],
    ))->getStatusCode())->toBe(Response::HTTP_OK);
});

it('treats an en-US visitor on an en-GB site as already served', function (): void {
    [, , , $primaryUrl] = visitorLanguageFixture('en-GB');
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    expect(visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'en-US,en;q=0.9'],
    ))->getStatusCode())->toBe(Response::HTTP_OK);
});

it('matches an fr-CA visitor to an fr site', function (): void {
    [, , , $primaryUrl, $frenchUrl] = visitorLanguageFixture();
    visitorLanguageMode(VisitorLanguageDetection::Redirect);

    $response = visitorLanguageRun(visitorLanguageRequest(
        'http://primary.example.com' . $primaryUrl->url,
        ['Accept-Language' => 'fr-CA,fr;q=0.8'],
    ));

    expect($response->getStatusCode())->toBe(Response::HTTP_FOUND);
    expect($response->headers->get('Location'))->toBe('http://fr.example.com' . $frenchUrl->url);
});

it('produces a pass-through response byte-identical to detection being off', function (): void {
    [, , , $primaryUrl] = visitorLanguageFixture();

    $url = 'http://primary.example.com' . $primaryUrl->url;
    $headers = ['Accept-Language' => 'fr-FR,fr;q=0.9', 'Sec-Fetch-Site' => 'same-origin'];

    visitorLanguageMode(VisitorLanguageDetection::Off);
    $off = visitorLanguageRun(visitorLanguageRequest($url, $headers));

    visitorLanguageMode(VisitorLanguageDetection::Redirect);
    $guarded = visitorLanguageRun(visitorLanguageRequest($url, $headers));

    // The HTML cache keys on host + path alone. A pass-through that differs by
    // even one header would be cached and replayed to every other visitor.
    expect((string) $guarded)->toBe((string) $off);
});
