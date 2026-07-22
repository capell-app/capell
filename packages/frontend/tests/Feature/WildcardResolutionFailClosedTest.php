<?php

declare(strict_types=1);

use Capell\Core\Enums\UrlParamTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Actions\ResolvePublicPageRequestAction;
use Capell\Frontend\Data\PageResolutionData;
use Capell\Frontend\Data\PublicPageResolutionInputData;

/**
 * Regression coverage for the soft-200 fail-open in the wildcard page resolver.
 *
 * A wildcard/url_params page must only resolve a deep path that genuinely
 * matches its declared shape. Before the fix, a page declaring positional or
 * label url_params (e.g. `post=int`, `page=int`) would resolve ANY deep path
 * under it with an empty param set, returning HTTP 200 for pages that do not
 * exist (the "70 theme-demo URLs" masking). Slug pages are intentionally
 * exempt: they extract no params at this layer and resolve the concrete record
 * downstream.
 */
function failClosedSite(): array
{
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    SiteDomain::factory()->enabled()->state([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => '/',
        'default' => true,
    ])->create();

    return [$site, $language];
}

/** @param array<string,string>|null $urlParams */
function failClosedPage(Site $site, Language $language, string $url, ?array $urlParams): Page
{
    $factory = Page::factory()->site($site)->withTranslations($language);

    if ($urlParams !== null) {
        $factory = $factory->for(Blueprint::factory()->page()->meta(['url_params' => $urlParams]));
    }

    $page = $factory->create();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => $url])->create();

    return $page;
}

function failClosedResolve(Site $site, Language $language, string $url): PageResolutionData
{
    return ResolvePublicPageRequestAction::run(new PublicPageResolutionInputData(
        site: $site,
        language: $language,
        url: $url,
    ));
}

it('resolves a genuine star wildcard page child path to the page', function (): void {
    [$site, $language] = failClosedSite();
    $page = failClosedPage($site, $language, '/blog/*', ['post' => UrlParamTypeEnum::Int->value]);

    $result = failClosedResolve($site, $language, '/blog/post/123');

    expect($result->isErrorPage)->toBeFalse()
        ->and($result->page?->getKey())->toBe($page->getKey())
        ->and($result->params)->toMatchArray(['post' => 123]);
});

it('resolves a non-star url_params page child path to the page', function (): void {
    [$site, $language] = failClosedSite();
    $page = failClosedPage($site, $language, '/blog', ['post' => UrlParamTypeEnum::Int->value]);

    $result = failClosedResolve($site, $language, '/blog/post/123');

    expect($result->isErrorPage)->toBeFalse()
        ->and($result->page?->getKey())->toBe($page->getKey())
        ->and($result->params)->toMatchArray(['post' => 123]);
});

it('still resolves slug wildcard pages so the record can be looked up downstream', function (): void {
    [$site, $language] = failClosedSite();
    $page = failClosedPage($site, $language, '/news/*', ['slug' => UrlParamTypeEnum::String->value]);

    $result = failClosedResolve($site, $language, '/news/my-article');

    expect($result->isErrorPage)->toBeFalse()
        ->and($result->page?->getKey())->toBe($page->getKey());
});

/*
 * Negative controls: these assert the fail-open is closed. Each one returned
 * HTTP 200 (isErrorPage === false, resolving to the wildcard page) BEFORE the
 * fix in ResolvePublicPageRequestAction::resolveViaWildcard, and now correctly
 * falls through to the 404 error page.
 */

it('404s an unmatched deep path under a non-star positional-param page (theme-demo case)', function (): void {
    [$site, $language] = failClosedSite();
    $page = failClosedPage($site, $language, '/blog', ['post' => UrlParamTypeEnum::Int->value]);

    $result = failClosedResolve($site, $language, '/blog/deep/nonsense');

    expect($result->isErrorPage)->toBeTrue()
        ->and($result->page?->getKey())->not->toBe($page->getKey());
});

it('404s an unmatched deep path under a star positional-param page', function (): void {
    [$site, $language] = failClosedSite();
    $page = failClosedPage($site, $language, '/blog/*', ['post' => UrlParamTypeEnum::Int->value]);

    $result = failClosedResolve($site, $language, '/blog/deep/nonsense');

    expect($result->isErrorPage)->toBeTrue()
        ->and($result->page?->getKey())->not->toBe($page->getKey());
});

it('404s a deep path under a pagination page that is not a valid pagination url', function (): void {
    [$site, $language] = failClosedSite();
    failClosedPage($site, $language, '/list', ['page' => UrlParamTypeEnum::Int->value]);

    $result = failClosedResolve($site, $language, '/list/deep/nonsense');

    expect($result->isErrorPage)->toBeTrue();
});

it('404s a deep path under a plain non-wildcard page', function (): void {
    [$site, $language] = failClosedSite();
    failClosedPage($site, $language, '/parent', null);

    $result = failClosedResolve($site, $language, '/parent/deep/nonsense');

    expect($result->isErrorPage)->toBeTrue();
});
