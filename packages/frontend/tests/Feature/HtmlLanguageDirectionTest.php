<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Locale\HtmlLanguageAttribute;
use Illuminate\Support\Facades\Cache;

/**
 * Builds the site and homepage for a language. The request itself is issued from
 * the test body, where $this is the typed TestCase.
 */
function seedLanguageHomepage(Language $language): void
{
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    Cache::flush();

    $site = Site::factory()
        ->withTranslations($language, siteDomainData: [
            'domain' => 'localhost',
            'scheme' => 'http',
            'path' => null,
            'default' => true,
        ])
        ->create(['language_id' => $language->id]);

    Page::factory()
        ->site($site)
        ->home()
        ->withTranslations($language, data: ['title' => 'Language homepage'], slug: '/')
        ->create(['meta' => null]);
}

it('emits the resolved site language on the html element', function (): void {
    $french = Language::factory()->french(isDefault: true)->create();

    seedLanguageHomepage($french);

    $this->followingRedirects()
        ->get('/', ['HTTP_HOST' => 'localhost'])
        ->assertOk()
        ->assertSee('lang="fr"', false);
});

it('emits a right-to-left direction for a right-to-left language', function (): void {
    $arabic = Language::factory()
        ->forCountry('العربية', 'ar', 'ar', 'sa', isDefault: true)
        ->create();

    seedLanguageHomepage($arabic);

    $this->followingRedirects()
        ->get('/', ['HTTP_HOST' => 'localhost'])
        ->assertOk()
        ->assertSee('dir="rtl"', false);
});

it('emits a left-to-right direction for a left-to-right language', function (): void {
    $french = Language::factory()->french(isDefault: true)->create();

    seedLanguageHomepage($french);

    $this->followingRedirects()
        ->get('/', ['HTTP_HOST' => 'localhost'])
        ->assertOk()
        ->assertSee('dir="ltr"', false);
});

it('falls back to the application locale when no language is resolved', function (): void {
    app()->setLocale('en');

    expect(HtmlLanguageAttribute::forLanguage(null))->toBe('en')
        ->and(Language::directionForCode(app()->getLocale()))->toBe('ltr');
});
