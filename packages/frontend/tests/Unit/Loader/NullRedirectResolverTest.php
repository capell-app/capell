<?php

declare(strict_types=1);

use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\RedirectResolver;
use Capell\Frontend\Support\Loader\NullRedirectResolver;

it('resolves wildcard home redirects while preserving the requested path and query string', function (): void {
    [$site, $language] = frontendNullRedirectSite();

    $redirect = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->type(UrlTypeEnum::Redirect)
        ->state([
            'url' => '/*',
            'target_url' => 'https://example.test/base/',
            'status_code' => RedirectStatusCodeEnum::Temporary,
            'status' => true,
        ])
        ->createOne();

    request()->server->set('QUERY_STRING', 'utm_source=capell');

    $resolver = resolve(RedirectResolver::class);
    $decision = expectPresent($resolver->resolve($site, $language, '/campaigns/spring'));

    expect($resolver)->toBeInstanceOf(NullRedirectResolver::class)
        ->and($decision)->not->toBeNull()
        ->and($decision->targetUrl)->toBe('https://example.test/base/campaigns/spring?utm_source=capell')
        ->and($decision->statusCode)->toBe(302)
        ->and($redirect->refresh()->hit_count)->toBe(1);
});

it('redirects to the canonical page URL when a redirect has no explicit target URL', function (): void {
    [$site, $language] = frontendNullRedirectSite();
    $page = Page::factory()->site($site)->createOne();

    PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->state([
            'url' => '/target',
            'type' => null,
            'status' => true,
        ])
        ->createOne();
    $redirect = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->type(UrlTypeEnum::Redirect)
        ->state([
            'url' => '/old-target',
            'target_url' => null,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'status' => true,
        ])
        ->createOne();

    $decision = expectPresent(resolve(RedirectResolver::class)->resolve($site, $language, '/old-target', pageUrl: $redirect));

    expect($decision)->not->toBeNull()
        ->and($decision->targetUrl)->toBe('/target')
        ->and($decision->statusCode)->toBe(301)
        ->and($redirect->refresh()->hit_count)->toBe(1);
});

it('does not redirect to a disabled canonical page url', function (): void {
    [$site, $language] = frontendNullRedirectSite();
    $page = Page::factory()->site($site)->createOne();

    PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->state([
            'url' => '/disabled-target',
            'type' => null,
            'status' => false,
        ])
        ->createOne();
    $redirect = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->page($page)
        ->type(UrlTypeEnum::Redirect)
        ->state([
            'url' => '/old-target',
            'target_url' => null,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'status' => true,
        ])
        ->createOne();

    expect(resolve(RedirectResolver::class)->resolve($site, $language, '/old-target', pageUrl: $redirect))
        ->toBeNull()
        ->and($redirect->refresh()->hit_count)->toBe(1);
});

it('returns null for non redirect page URLs and missing wildcard redirects', function (): void {
    [$site, $language] = frontendNullRedirectSite();
    $pageUrl = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/ordinary-page',
            'type' => null,
            'status' => true,
        ])
        ->createOne();

    $resolver = resolve(RedirectResolver::class);

    expect($resolver->resolve($site, $language, '/ordinary-page', pageUrl: $pageUrl))->toBeNull()
        ->and($resolver->resolve($site, $language, '/missing'))->toBeNull();
});

/**
 * @return array{0: Site, 1: Language}
 */
function frontendNullRedirectSite(): array
{
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language)
        ->createOne();

    return [$site, $language];
}
