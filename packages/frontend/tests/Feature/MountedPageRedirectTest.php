<?php

declare(strict_types=1);

use Capell\Core\Contracts\RedirectResolver;
use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Redirects\PageUrlRedirectResolver;
use Capell\Frontend\Actions\ResolvePublicPageRequestAction;
use Capell\Frontend\Data\PublicPageResolutionInputData;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Loader\NullRedirectResolver;
use Capell\Frontend\Tests\Support\LegacyDecisionRedirectResolverForMountedRoute;
use Illuminate\Http\RedirectResponse;
use PHPUnit\Framework\Assert;

it('follows bilingual moved page redirects through the correct domain mount exactly once', function (bool $siteUrls, string $resolverClass, bool $explicitTarget): void {
    config([
        'capell-frontend.html_cache' => false,
        'capell-frontend.write_html_cache' => false,
        'capell-frontend.use_site_domain_for_urls' => $siteUrls,
        'redirects.auto_redirects.enabled' => true,
        'redirects.auto_redirects.status_code' => 301,
    ]);
    app()->instance(RedirectResolver::class, resolve($resolverClass));
    [$site, $english, $french, $englishDomain, $frenchDomain] = mountedRedirectSite();
    $parentA = mountedRedirectPage($site, [$english, $french], 'parent-a', 'English parent A', 'Parent français A');
    $parentB = mountedRedirectPage($site, [$english, $french], 'parent-b', 'English parent B', 'Parent français B');
    $child = mountedRedirectPage($site, [$english, $french], 'child', 'English child marker', 'Marqueur enfant français', $parentA);
    $oldEnglishPath = $child->pageUrls()->where('language_id', $english->id)->whereNull('type')->sole()->url;
    $oldFrenchPath = $child->pageUrls()->where('language_id', $french->id)->whereNull('type')->sole()->url;

    $this->get($frenchDomain->full_url . $oldFrenchPath)
        ->assertOk()
        ->assertSeeText('Marqueur enfant français')
        ->assertSeeText('Marqueur enfant français body')
        ->assertDontSeeText('English child marker body');

    $child->update(['parent_id' => $parentB->id]);
    $englishCurrent = $child->pageUrls()->where('language_id', $english->id)->whereNull('type')->sole();
    $frenchCurrent = $child->pageUrls()->where('language_id', $french->id)->whereNull('type')->sole();
    $englishRedirect = $child->pageUrls()->where('language_id', $english->id)->autoRedirects()->where('url', $oldEnglishPath)->sole();
    $frenchRedirect = $child->pageUrls()->where('language_id', $french->id)->autoRedirects()->where('url', $oldFrenchPath)->sole();

    expect($oldEnglishPath)->toBe('/parent-a/child')
        ->and($oldFrenchPath)->toBe('/parent-a-fr/child-fr')
        ->and($englishCurrent->url)->toBe('/parent-b/child')
        ->and($frenchCurrent->url)->toBe('/parent-b-fr/child-fr')
        ->and($englishRedirect->target_url)->toBe($englishCurrent->url)
        ->and($frenchRedirect->target_url)->toBe($frenchCurrent->url);

    if (! $explicitTarget) {
        $englishRedirect->update(['target_url' => null]);
        $frenchRedirect->update(['target_url' => null]);
    }

    $englishCurrent->setRelation('siteDomain', $englishDomain);
    $frenchCurrent->setRelation('siteDomain', $frenchDomain);
    $frenchResponse = $this->get($frenchDomain->full_url . $oldFrenchPath)
        ->assertStatus(301)
        ->assertRedirect($frenchCurrent->full_url);
    $frenchLocation = $frenchResponse->headers->get('Location');
    Assert::assertIsString($frenchLocation);
    expect(substr_count($frenchLocation, '/mount/fr'))->toBe(1);
    $this->get($frenchLocation)
        ->assertOk()
        ->assertSeeText('Marqueur enfant français')
        ->assertSeeText('Marqueur enfant français body')
        ->assertDontSeeText('English child marker body')
        ->assertDontSee('data-capell-authoring', false)
        ->assertDontSee('data-capell-editor', false)
        ->assertDontSee('field_path', false)
        ->assertDontSee('signedEditorUrl', false)
        ->assertDontSee('signature=', false);
    $englishResponse = $this->get($englishDomain->full_url . $oldEnglishPath)
        ->assertStatus(301)
        ->assertRedirect($englishCurrent->full_url);
    $englishLocation = $englishResponse->headers->get('Location');
    Assert::assertIsString($englishLocation);
    expect($englishLocation)->not->toContain('/mount/fr');
    $this->get($englishLocation)
        ->assertOk()
        ->assertSeeText('English child marker body')
        ->assertDontSeeText('Marqueur enfant français body');

    $expectedHits = $resolverClass === PageUrlRedirectResolver::class ? 1 : 0;
    expect($frenchRedirect->refresh()->hit_count)->toBe($expectedHits)
        ->and($englishRedirect->refresh()->hit_count)->toBe($expectedHits)
        ->and($frenchCurrent->refresh()->url)->toBe('/parent-b-fr/child-fr')
        ->and($frenchRedirect->target_url)->toBe($explicitTarget ? '/parent-b-fr/child-fr' : null);
})->with([false, true])->with([
    'Core resolver' => PageUrlRedirectResolver::class,
    'Frontend fallback resolver' => NullRedirectResolver::class,
])->with(['explicit automatic target' => true, 'legacy canonical fallback' => false]);

it('qualifies automatic wildcard decisions without an exact input page url', function (bool $siteUrls, string $resolverClass): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false, 'capell-frontend.use_site_domain_for_urls' => $siteUrls]);
    app()->instance(RedirectResolver::class, resolve($resolverClass));
    [$site, , $french, , $frenchDomain] = mountedRedirectSite();
    $redirect = PageUrl::factory()->site($site)->language($french)->type(UrlTypeEnum::Redirect)->createOne([
        'url' => '/*', 'target_url' => '/new-base', 'is_manual' => false, 'status_code' => RedirectStatusCodeEnum::Temporary,
    ]);
    expect(PageUrl::query()->where('site_id', $site->id)->where('language_id', $french->id)->where('url', '/missing/path')->exists())->toBeFalse();

    $this->get($frenchDomain->full_url . '/missing/path?campaign=mounted')
        ->assertStatus(302)
        ->assertRedirect($frenchDomain->full_url . '/new-base/missing/path?campaign=mounted');

    expect($redirect->refresh()->hit_count)->toBe($resolverClass === PageUrlRedirectResolver::class ? 1 : 0)
        ->and($redirect->target_url)->toBe('/new-base');
})->with([false, true])->with([PageUrlRedirectResolver::class, NullRedirectResolver::class]);

it('keeps automatic absolute targets outside the site url generator', function (string $target, string $resolverClass): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false, 'capell-frontend.use_site_domain_for_urls' => true]);
    app()->instance(RedirectResolver::class, resolve($resolverClass));
    [$site, , $french, , $frenchDomain] = mountedRedirectSite();
    $redirect = PageUrl::factory()->site($site)->language($french)->type(UrlTypeEnum::Redirect)->createOne([
        'url' => '/absolute', 'target_url' => $target, 'is_manual' => false, 'status_code' => RedirectStatusCodeEnum::Temporary,
    ]);

    $response = $this->get($frenchDomain->full_url . '/absolute')->assertStatus(302);

    expect($response->headers->get('Location'))->toBe($target)
        ->and($redirect->refresh()->hit_count)->toBe($resolverClass === PageUrlRedirectResolver::class ? 1 : 0);
})->with(['https://elsewhere.test/new?source=redirect#content', '//elsewhere.test/new'])->with([PageUrlRedirectResolver::class, NullRedirectResolver::class]);

it('preserves explicit manual and legacy custom resolver addressing', function (string $target, bool $legacyResolver): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false, 'capell-frontend.use_site_domain_for_urls' => false]);
    app()->instance(RedirectResolver::class, $legacyResolver ? resolve(LegacyDecisionRedirectResolverForMountedRoute::class) : resolve(PageUrlRedirectResolver::class));
    [$site, $english, $french, , $frenchDomain] = mountedRedirectSite();
    $page = mountedRedirectPage($site, [$english, $french], 'current', 'English current', 'Page française');
    $redirect = PageUrl::factory()->site($site)->language($french)->page($page)->type(UrlTypeEnum::Redirect)->createOne([
        'url' => '/authored', 'target_url' => $target, 'status_code' => RedirectStatusCodeEnum::Temporary, 'is_manual' => ! $legacyResolver,
    ]);

    $response = $this->get($frenchDomain->full_url . '/authored')->assertStatus(302);
    $location = $response->headers->get('Location');

    expect($location)->toBe(str_starts_with($target, '/') ? 'http://mounted.example.test:8123' . $target : $target)
        ->and($redirect->refresh()->hit_count)->toBe(1);
})->with(['/origin-relative', 'https://elsewhere.test/authored?source=manual'])->with([false, true]);

it('does not qualify generated targets using a loaded domain from another site or language', function (bool $wrongSite): void {
    [$site, $english, $french, $englishDomain] = mountedRedirectSite();
    $page = mountedRedirectPage($site, [$english, $french], 'current', 'English current', 'Page française');
    PageUrl::factory()->site($site)->language($french)->page($page)->type(UrlTypeEnum::Redirect)->createOne([
        'url' => '/former', 'target_url' => '/current-fr', 'is_manual' => false,
    ]);
    $unrelatedDomain = $wrongSite
        ? SiteDomain::factory()->language($french)->createOne(['path' => '/foreign'])
        : $englishDomain;
    $site->setRelation('siteDomain', $unrelatedDomain);

    $resolution = ResolvePublicPageRequestAction::run(new PublicPageResolutionInputData(site: $site, language: $french, url: '/former'));
    Assert::assertInstanceOf(RedirectResponse::class, $resolution->redirect);

    expect($resolution->redirect->getTargetUrl())->toBe('/current-fr');
})->with(['wrong language' => false, 'wrong site' => true]);

it('uses the current mount when two domains share a warm site and language cache', function (bool $siteUrls): void {
    config(['capell-frontend.html_cache' => false, 'capell-frontend.write_html_cache' => false, 'capell-frontend.use_site_domain_for_urls' => $siteUrls]);
    app()->instance(RedirectResolver::class, resolve(PageUrlRedirectResolver::class));
    [$site, $english, $french, , $frenchDomain] = mountedRedirectSite();
    $secondDomain = SiteDomain::factory()->site($site)->language($french)->createOne([
        'domain' => 'mounted.example.test', 'scheme' => 'http', 'port' => 8123,
        'path' => '/another/fr', 'default' => false, 'status' => true,
    ]);
    $page = mountedRedirectPage($site, [$english, $french], 'current', 'English current', 'Page française');
    PageUrl::factory()->site($site)->language($french)->page($page)->type(UrlTypeEnum::Redirect)->createOne([
        'url' => '/former', 'target_url' => '/current-fr', 'is_manual' => false,
    ]);

    $this->get($frenchDomain->full_url . '/former')->assertRedirect($frenchDomain->full_url . '/current-fr');
    $cachedSite = $site->fresh(['translations', 'siteDomains']);
    Assert::assertInstanceOf(Site::class, $cachedSite);
    $cachedSite->setRelation('siteDomain', $frenchDomain);
    $cachedSite->setRelation('translation', $cachedSite->translations->firstWhere('language_id', $french->id));
    CapellCore::setToCache(CacheEnum::site($site->id, $french->id), $cachedSite);
    $this->get($secondDomain->full_url . '/former')->assertRedirect($secondDomain->full_url . '/current-fr');
    $this->get($frenchDomain->full_url . '/former')->assertRedirect($frenchDomain->full_url . '/current-fr');
})->with([false, true]);

/** @return array{Site, Language, Language, SiteDomain, SiteDomain} */
function mountedRedirectSite(): array
{
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english, $french], siteDomainData: [
        'domain' => 'mounted.example.test', 'scheme' => 'http', 'port' => 8123, 'path' => null,
    ])->createOne();
    $englishDomain = $site->siteDomains()->where('language_id', $english->id)->sole();
    $frenchDomain = $site->siteDomains()->where('language_id', $french->id)->sole();
    $frenchDomain->update(['path' => '/mount/fr']);

    return [$site, $english, $french, $englishDomain, $frenchDomain];
}

/** @param list<Language> $languages */
function mountedRedirectPage(Site $site, array $languages, string $slug, string $englishTitle, string $frenchTitle, ?Page $parent = null): Page
{
    $page = Page::factory()->site($site)->withTranslations($languages, slug: $slug)->published()->createOne([
        'parent_id' => $parent?->id,
        'content_structure_override' => ContentStructure::Html->value,
    ]);
    foreach ($languages as $language) {
        $title = $language->id === $site->language_id ? $englishTitle : $frenchTitle;
        $translation = $page->translations()->where('language_id', $language->id)->sole();
        $meta = $translation->meta;
        $translation->update([
            'title' => $title,
            'content' => '<p>' . $title . ' body</p>',
            'meta' => array_replace(is_array($meta) ? $meta : [], ['slug' => $language->id === $site->language_id ? $slug : $slug . '-fr']),
        ]);
    }

    return $page;
}
