<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\FrontendKernelInterface;
use Capell\Frontend\Support\Locale\FrontendLocaleScope;
use Capell\Tests\Support\Concerns\TestingFrontend;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

uses(TestingFrontend::class);

/**
 * The public locale must be a pure function of host + path, never of request
 * headers: the public HTML cache is keyed on host + path alone.
 */
function localeSiteRequest(Language $language, string $domainName): Request
{
    $site = Site::factory()
        ->withTranslations($language, siteDomainData: [
            'default' => true,
            'domain' => $domainName,
            'scheme' => 'http',
            'path' => null,
        ])
        ->create(['language_id' => $language->id]);

    Page::factory()->site($site)->home()->withTranslations($language, slug: '/')->create();

    return Request::create(
        'http://' . $domainName . '/',
        Symfony\Component\HttpFoundation\Request::METHOD_GET,
        server: ['HTTP_HOST' => $domainName],
    );
}

it('applies the resolved site language to the application and Carbon locale', function (): void {
    config()->set('app.locale', 'en');
    app()->setLocale('en');

    $french = Language::factory()->french(isDefault: true)->create();

    resolve(FrontendKernelInterface::class)->bootstrap(localeSiteRequest($french, 'fr.example.com'));

    expect(app()->getLocale())->toBe('fr');
    expect(Carbon::getLocale())->toBe('fr');
    expect(CarbonImmutable::getLocale())->toBe('fr');
});

it('leaves the application locale untouched on a default-language domain', function (): void {
    config()->set('app.locale', 'en');
    app()->setLocale('en');

    $english = Language::factory()->english()->create();

    resolve(FrontendKernelInterface::class)->bootstrap(localeSiteRequest($english, 'en.example.com'));

    expect(app()->getLocale())->toBe('en');
    expect(Carbon::getLocale())->toBe('en');
});

it('ignores the Accept-Language header when resolving the public locale', function (): void {
    config()->set('app.locale', 'en');
    app()->setLocale('en');

    $french = Language::factory()->french(isDefault: true)->create();

    $request = localeSiteRequest($french, 'fr.example.com');
    $request->headers->set('Accept-Language', 'de-DE,de;q=0.9');

    resolve(FrontendKernelInterface::class)->bootstrap($request);

    expect(app()->getLocale())->toBe('fr');
});

it('restores the incoming default locale so it cannot leak into a later request', function (): void {
    config()->set('app.locale', 'en');
    app()->setLocale('en');

    $french = Language::factory()->french(isDefault: true)->create();

    resolve(FrontendKernelInterface::class)->bootstrap(localeSiteRequest($french, 'fr.example.com'));

    expect(app()->getLocale())->toBe('fr');

    resolve(FrontendLocaleScope::class)->restore();

    expect(app()->getLocale())->toBe('en');
    expect(Carbon::getLocale())->toBe('en');
    expect(CarbonImmutable::getLocale())->toBe('en');
});

it('restores the incoming default locale when Octane state is flushed', function (): void {
    config()->set('app.locale', 'en');
    app()->setLocale('en');

    $french = Language::factory()->french(isDefault: true)->create();

    resolve(FrontendKernelInterface::class)->bootstrap(localeSiteRequest($french, 'fr.example.com'));

    resolve(FrontendLocaleScope::class)->flushOctaneState();

    expect(app()->getLocale())->toBe('en');
});

it('refuses unsafe locale values', function (string $locale): void {
    expect(FrontendLocaleScope::isSafeLocale($locale))->toBeFalse();
})->with(['', '../../etc/passwd', 'fr/FR', 'fr\\FR', 'fr FR']);
