<?php

declare(strict_types=1);

use Capell\Core\Enums\UrlParamTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ParseWildcardPageUrlAction;

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

/**
 * Build the minimum database objects needed for ParseWildcardPageUrlAction:
 * a site, language, page with url_params, and a PageUrl with the given pattern.
 *
 * @param  array<string, string>  $urlParamsSpec
 */
function makeWildcardFixture(string $pattern, array $urlParamsSpec): PageUrl
{
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => $urlParamsSpec]))->create();

    return PageUrl::factory()->site($site)->page($page)->state(['url' => $pattern])->create();
}

// ─────────────────────────────────────────────────────────────
// handleNoWildcard path: pattern has no *, but spec is non-empty
// and the request URL extends beyond the pattern base.
// ─────────────────────────────────────────────────────────────

dataset('no-wildcard url variants', [
    // [ pattern, urlParamsSpec, requestUrl, expectedParams ]

    // ordered single param (no wildcard, url extends base)
    // 'page' key with int type activates shouldEnforcePaginationMode; use 'simple' mode so a bare
    // numeric segment passes the mode check.
    'single int param via ordered extraction' => [
        '/blog',
        ['page' => UrlParamTypeEnum::Int->value],
        '/blog/3',
        ['page' => 3],
        'simple',
        [],
    ],

    // ordered string param
    'single string param via ordered extraction' => [
        '/shop',
        ['category' => UrlParamTypeEnum::String->value],
        '/shop/electronics',
        ['category' => 'electronics'],
        null,
        [],
    ],

    // label-value extraction (e.g. /archive/year/2024)
    'label-value pair extraction without wildcard' => [
        '/archive',
        ['year' => UrlParamTypeEnum::Int->value],
        '/archive/year/2024',
        ['year' => 2024],
        null,
        [],
    ],

    // pagination: simple mode — single numeric segment
    'simple pagination no-wildcard extracts page param' => [
        '/news',
        ['page' => UrlParamTypeEnum::Int->value],
        '/news/2',
        ['page' => 2],
        'simple',
        [],
    ],

    // pagination: normal mode — page/N segments
    'normal pagination no-wildcard extracts page param' => [
        '/posts',
        ['page' => UrlParamTypeEnum::Int->value],
        '/posts/page/3',
        ['page' => 3],
        'normal',
        [],
    ],

    // pagination: dashed mode — page-N segment
    'dashed pagination no-wildcard extracts page param' => [
        '/articles',
        ['page' => UrlParamTypeEnum::Int->value],
        '/articles/page-5',
        ['page' => 5],
        'dashed',
        [],
    ],

    // enforce invalid pagination: non-integer value in simple mode
    'invalid pagination value sets invalidPagination flag' => [
        '/feed',
        ['page' => UrlParamTypeEnum::Int->value],
        '/feed/not-a-number',
        [],
        'simple',
        ['enforceInvalidPageValue' => true],
    ],
]);

/**
 * @param  array<string, string>  $urlParamsSpec
 * @param  array<string, mixed>  $expectedParams
 * @param  array<string, mixed>  $options
 */
it('handleNoWildcard: $variant', function (
    string $pattern,
    array $urlParamsSpec,
    string $requestUrl,
    array $expectedParams,
    ?string $paginationMode,
    array $options,
): void {
    $pageUrl = makeWildcardFixture($pattern, $urlParamsSpec);

    $result = ParseWildcardPageUrlAction::run($pageUrl, $requestUrl, [], $paginationMode, $options);

    if (isset($options['enforceInvalidPageValue']) && $options['enforceInvalidPageValue'] === true && $expectedParams === []) {
        expect($result['invalidPagination'] ?? false)->toBeTrue();
    } else {
        expect($result['params'] ?? [])->toMatchArray($expectedParams);
    }
})->with('no-wildcard url variants');

// ─────────────────────────────────────────────────────────────
// handleNoWildcard: slug stripped before ordered param extraction
// ─────────────────────────────────────────────────────────────

it('strips leading slug segment before extracting ordered params in no-wildcard path', function (): void {
    // Spec has slug + page: the slug consumes the first segment, page gets the second.
    $pageUrl = makeWildcardFixture('/blog', [
        'slug' => UrlParamTypeEnum::String->value,
        'page' => UrlParamTypeEnum::Int->value,
    ]);

    $result = ParseWildcardPageUrlAction::run($pageUrl, '/blog/my-article/4', [], 'simple');

    // Slug is stripped; page = 4 is extracted as ordered param.
    expect($result['params']['page'] ?? null)->toBe(4)
        ->and($result['params'])->not()->toHaveKey('slug');
});

// ─────────────────────────────────────────────────────────────
// handleNoWildcard: URL does not extend beyond base → no params
// ─────────────────────────────────────────────────────────────

it('produces no params when the request url exactly matches the base pattern with no extension', function (): void {
    $pageUrl = makeWildcardFixture('/shop', ['category' => UrlParamTypeEnum::String->value]);

    // URL matches /shop exactly — no trailing segments, spec is non-empty.
    // str_starts_with('/shop', '/shop/') is false, so handleNoWildcard is NOT triggered.
    $result = ParseWildcardPageUrlAction::run($pageUrl, '/shop', []);

    expect($result['params'] ?? [])->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────
// baseFromPattern: multi-segment non-wildcard pattern
// ─────────────────────────────────────────────────────────────

it('baseFromPattern sets the correct pageSlug for a multi-segment non-wildcard pattern', function (): void {
    $pageUrl = makeWildcardFixture('/shop/products', ['id' => UrlParamTypeEnum::Int->value]);

    $result = ParseWildcardPageUrlAction::run($pageUrl, '/shop/products/42', []);

    // baseFromPattern strips no wildcards, so pageSlug = '/shop/products'
    expect($result['pageSlug'] ?? null)->toBe('/shop/products');
});

// ─────────────────────────────────────────────────────────────
// handleMultipleWildcards: segment-count mismatch → no params
// (tests branch where count($patternSegments) !== count($urlSegments))
// ─────────────────────────────────────────────────────────────

it('multiple wildcards returns empty params when segment counts differ', function (): void {
    $pageUrl = makeWildcardFixture('/cat/*/*', [
        'type' => UrlParamTypeEnum::String->value,
        'id' => UrlParamTypeEnum::Int->value,
    ]);

    $result = ParseWildcardPageUrlAction::run($pageUrl, '/cat/books', []);

    expect($result['params'] ?? [])->toBeEmpty();
});

// ─────────────────────────────────────────────────────────────
// handleSingleWildcard: shouldEnforcePaginationMode=true but
// remaining segments don't match any pagination mode → empty params
// ─────────────────────────────────────────────────────────────

it('single wildcard with enforced pagination mode rejects mismatched url structure', function (): void {
    // Only 'page' int key → shouldEnforcePaginationMode becomes true.
    $pageUrl = makeWildcardFixture('/news/*', ['page' => UrlParamTypeEnum::Int->value]);

    // URL has two extra segments which don't match simple/normal/dash modes.
    $result = ParseWildcardPageUrlAction::run($pageUrl, '/news/page/2/extra', [], 'simple');

    expect($result['params'] ?? [])->not()->toHaveKey('page');
});
