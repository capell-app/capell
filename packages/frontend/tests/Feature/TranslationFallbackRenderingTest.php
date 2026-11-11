<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config([
        'capell-frontend.html_cache' => false,
        'capell-frontend.write_html_cache' => false,
    ]);
});

/**
 * @return array{Site, Language, Language, SiteDomain, SiteDomain}
 */
function translationFallbackSite(bool $frenchFallsBackToEnglish): array
{
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne([
        'fallback_language_id' => $frenchFallsBackToEnglish ? $english->getKey() : null,
    ]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french], siteDomainData: [
        'domain' => 'fallback.example.test', 'scheme' => 'http', 'port' => 8124, 'path' => null,
    ])->createOne();

    return [
        $site,
        $english,
        $french,
        $site->siteDomains()->where('language_id', $english->getKey())->sole(),
        $site->siteDomains()->where('language_id', $french->getKey())->sole(),
    ];
}

/**
 * @param  array<int, array{0: Language, 1: string, 2: string}>  $translations  language, slug, title
 */
function translationFallbackSitePage(Site $site, array $translations): Page
{
    $languages = array_map(static fn (array $translation): Language => $translation[0], $translations);
    $page = Page::factory()->site($site)->withTranslations($languages)->published()->createOne([
        'content_structure_override' => ContentStructure::Html->value,
    ]);

    foreach ($translations as [$language, $slug, $title]) {
        $translation = $page->translations()->where('language_id', $language->getKey())->sole();
        $meta = is_array($translation->meta) ? $translation->meta : [];
        $translation->update([
            'title' => $title,
            'content' => '<p>' . $title . ' body</p>',
            'meta' => array_replace($meta, ['slug' => $slug]),
        ]);
    }

    return $page;
}

/**
 * @return list<string>
 */
function translationFallbackHreflangs(TestResponse $response): array
{
    preg_match_all('/<link\s[^>]*hreflang="([^"]+)"[^>]*rel="alternate"[^>]*>/', (string) $response->getContent(), $matches);

    return array_values(array_diff($matches[1], ['x-default']));
}

function translationFallbackCanonical(TestResponse $response): ?string
{
    preg_match('/<link\s[^>]*href="([^"]+)"[^>]*rel="canonical"/', (string) $response->getContent(), $match);

    return $match[1] ?? null;
}

function translationFallbackRobots(TestResponse $response): string
{
    preg_match('/<meta\s+name="robots"\s+content="([^"]*)"/', (string) $response->getContent(), $match);

    return $match[1] ?? '';
}

it('serves the configured fallback language when a page has no translation in the requested language', function (): void {
    [$site, $english, , , $frenchDomain] = translationFallbackSite(frenchFallsBackToEnglish: true);
    translationFallbackSitePage($site, [[$english, 'about', 'English about marker']]);

    $this->get($frenchDomain->full_url . '/about')
        ->assertOk()
        ->assertSeeText('English about marker body')
        ->assertDontSee('data-capell-authoring', false)
        ->assertDontSee('data-capell-editor', false)
        ->assertDontSee('field_path', false);
});

it('answers 404 for a missing translation when the language has no fallback', function (): void {
    [$site, $english, , , $frenchDomain] = translationFallbackSite(frenchFallsBackToEnglish: false);
    translationFallbackSitePage($site, [[$english, 'about', 'English about marker']]);

    $this->get($frenchDomain->full_url . '/about')
        ->assertNotFound()
        ->assertDontSeeText('English about marker body');
});

it('serves the real translation, indexable and in its own language, when one exists', function (): void {
    [$site, $english, $french, , $frenchDomain] = translationFallbackSite(frenchFallsBackToEnglish: true);
    translationFallbackSitePage($site, [
        [$english, 'about', 'English about marker'],
        [$french, 'a-propos', 'Marqueur français'],
    ]);

    $response = $this->get($frenchDomain->full_url . '/a-propos')
        ->assertOk()
        ->assertSeeText('Marqueur français body')
        ->assertDontSeeText('English about marker body')
        ->assertSee('lang="fr"', false);

    expect(translationFallbackRobots($response))->not->toContain('noindex')
        ->and(translationFallbackCanonical($response))->toBe($frenchDomain->full_url . '/a-propos')
        ->and(translationFallbackHreflangs($response))->toContain('fr', 'en');
});

it('does not serve the fallback language under the source slug once the page is translated', function (): void {
    [$site, $english, $french, , $frenchDomain] = translationFallbackSite(frenchFallsBackToEnglish: true);
    translationFallbackSitePage($site, [
        [$english, 'about', 'English about marker'],
        [$french, 'a-propos', 'Marqueur français'],
    ]);

    $this->get($frenchDomain->full_url . '/about')
        ->assertDontSeeText('English about marker body');
});

it('marks a fallback-served page in the head: served language, noindex, canonical and no alternate', function (): void {
    [$site, $english, , $englishDomain, $frenchDomain] = translationFallbackSite(frenchFallsBackToEnglish: true);
    $german = Language::factory()->german()->createOne();
    SiteDomain::factory()->site($site)->language($german)->createOne([
        'domain' => 'fallback.example.test', 'scheme' => 'http', 'port' => 8124, 'path' => '/de',
    ]);
    translationFallbackSitePage($site, [
        [$english, 'about', 'English about marker'],
        [$german, 'ueber', 'Deutsche Seite'],
    ]);

    $response = $this->get($frenchDomain->full_url . '/about')
        ->assertOk()
        ->assertSeeText('English about marker body')
        ->assertSee('lang="en"', false)
        ->assertDontSee('lang="fr"', false);

    expect(translationFallbackRobots($response))->toContain('noindex')
        ->and(translationFallbackCanonical($response))->toBe($englishDomain->full_url . '/about')
        ->and(translationFallbackHreflangs($response))->toContain('en', 'de')
        ->and(translationFallbackHreflangs($response))->not->toContain('fr');
});
