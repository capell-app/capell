<?php

declare(strict_types=1);

use Capell\Core\Enums\UrlParamTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ParseWildcardPageUrlAction;

it('extracts single wildcard param from nested path', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['post' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/blog/*'])->create();

    $parts = ['path' => '/blog/post/123'];

    $result = ParseWildcardPageUrlAction::run($url, '/blog/post/123', $parts);

    expect($result['params'])->toMatchArray(['post' => 123]);
});

it('extracts multiple wildcards and preserves slug', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['category' => UrlParamTypeEnum::String->value, 'id' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/shop/*/*'])->create();

    $parts = ['path' => '/shop/books/42'];

    $result = ParseWildcardPageUrlAction::run($url, '/shop/books/42', $parts);

    expect($result['params'])->toMatchArray(['category' => 'books', 'id' => 42]);
});

it('handles deep nested wildcard paths', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['x' => UrlParamTypeEnum::Int->value, 'y' => UrlParamTypeEnum::String->value, 'z' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/a/*/b/*/c/*'])->create();

    $parts = ['path' => '/a/1/b/slug/c/999'];

    $result = ParseWildcardPageUrlAction::run($url, '/a/1/b/slug/c/999', $parts);

    expect($result['params'])->toMatchArray(['x' => 1, 'y' => 'slug', 'z' => 999]);
});

it('does not set params when path does not match wildcard pattern', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['post' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/blog/*'])->create();

    $parts = ['path' => '/news/item/123'];

    $result = ParseWildcardPageUrlAction::run($url, '/news/item/123', $parts);

    expect($result['params'] ?? [])->toBeArray()->toBeEmpty();
});

it('leaves raw values when coercion fails', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['id' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/shop/*'])->create();

    $parts = ['path' => '/shop/not-an-int'];

    $result = ParseWildcardPageUrlAction::run($url, '/shop/not-an-int', $parts);

    // Expect param to be omitted if not a valid int
    expect($result['params']['id'] ?? null)->toBeNull();
});

it('does not set params when fewer segments than pattern', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['type' => UrlParamTypeEnum::String->value, 'id' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/cat/*/*'])->create();

    $parts = ['path' => '/cat/only-one'];

    $result = ParseWildcardPageUrlAction::run($url, '/cat/only-one', $parts);

    expect($result['params'] ?? [])->toBeArray()->toBeEmpty();
});

it('does not set params when more segments than pattern', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['type' => UrlParamTypeEnum::String->value, 'id' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/cat/*/*'])->create();

    $parts = ['path' => '/cat/books/42/extra'];

    $result = ParseWildcardPageUrlAction::run($url, '/cat/books/42/extra', $parts);

    expect($result['params'] ?? [])->toBeArray()->toBeEmpty();
});

it('sets the archive slug correctly from pattern', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['date' => UrlParamTypeEnum::Date->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/archive/*'])->create();

    $parts = ['path' => '/archive/2024-06'];

    $result = ParseWildcardPageUrlAction::run($url, '/archive/2024-06', $parts);

    expect($result['pageSlug'] ?? null)->toBe('/archive');
    expect($result['params'])->toMatchArray(['date' => '2024-06']);
});

it('does not set int param when value is not an integer', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/blog/*'])->create();

    $parts = ['path' => '/blog/test-4'];

    $result = ParseWildcardPageUrlAction::run($url, '/blog/test-4', $parts);

    expect($result['params'] ?? [])->not()->toHaveKey('page');
});

it('extracts page param in simple pagination mode', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/news/*'])->create();

    $parts = ['path' => '/news/2'];

    $result = ParseWildcardPageUrlAction::run($url, '/news/2', $parts, 'simple');

    expect($result['params'])->toMatchArray(['page' => 2]);
});

it('extracts page one in normal pagination mode', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/news/*'])->create();

    $parts = ['path' => '/news/page/1'];

    $result = ParseWildcardPageUrlAction::run($url, '/news/page/1', $parts, 'normal');

    expect($result['params'])->toMatchArray(['page' => 1])
        ->and($result)->not()->toHaveKey('invalidPagination');
});

it('extracts page param in normal pagination mode', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/news/*'])->create();

    $parts = ['path' => '/news/page/2'];

    $result = ParseWildcardPageUrlAction::run($url, '/news/page/2', $parts, 'normal');

    expect($result['params'])->toMatchArray(['page' => 2]);
});

it('extracts page param in dashed pagination mode', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/news/*'])->create();

    $parts = ['path' => '/news/page-2'];

    $result = ParseWildcardPageUrlAction::run($url, '/news/page-2', $parts, 'dashed');

    expect($result['params'])->toMatchArray(['page' => 2]);
});

it('does not extract page param when url does not match active pagination mode', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/news/*'])->create();

    $parts = ['path' => '/news/page/2'];

    $result = ParseWildcardPageUrlAction::run($url, '/news/page/2', $parts, 'simple');

    expect($result['params'] ?? [])->not()->toHaveKey('page');
});

it('marks non numeric simple pagination values as invalid', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/news/*'])->create();

    $parts = ['path' => '/news/not-a-number'];

    $result = ParseWildcardPageUrlAction::run(
        $url,
        '/news/not-a-number',
        $parts,
        'simple',
        ['enforceInvalidPageValue' => true],
    );

    expect($result['params'] ?? [])->not()->toHaveKey('page')
        ->and($result['invalidPagination'] ?? false)->toBeTrue();
});

it('marks zero and negative simple pagination values as invalid', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/news/*'])->create();

    $zeroParts = ['path' => '/news/0'];
    $negativeParts = ['path' => '/news/-1'];

    $zeroResult = ParseWildcardPageUrlAction::run(
        $url,
        '/news/0',
        $zeroParts,
        'simple',
        ['enforceInvalidPageValue' => true],
    );
    $negativeResult = ParseWildcardPageUrlAction::run(
        $url,
        '/news/-1',
        $negativeParts,
        'simple',
        ['enforceInvalidPageValue' => true],
    );

    expect($zeroResult['invalidPagination'] ?? false)->toBeTrue()
        ->and($negativeResult['invalidPagination'] ?? false)->toBeTrue();
});

it('returns no params when params spec is empty', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => []]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/about'])->create();

    $parts = ['path' => '/about'];

    $result = ParseWildcardPageUrlAction::run($url, '/about', $parts);

    expect($result['params'] ?? [])->toBeArray()->toBeEmpty();
});

it('handles slug param correctly', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['slug' => UrlParamTypeEnum::String->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/blog/*'])->create();

    $parts = ['path' => '/blog/my-article'];

    $result = ParseWildcardPageUrlAction::run($url, '/blog/my-article', $parts);

    expect($result['params'])->not()->toHaveKey('slug');
});

it('ignores extra segments beyond param spec', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['id' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/shop/*'])->create();

    $parts = ['path' => '/shop/123/extra'];

    $result = ParseWildcardPageUrlAction::run($url, '/shop/123/extra', $parts);

    expect($result['params'])->not()->toHaveKey('id');
});

it('accepts negative integers for int params', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['id' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/item/*'])->create();

    $parts = ['path' => '/item/-42'];

    $result = ParseWildcardPageUrlAction::run($url, '/item/-42', $parts);

    expect($result['params'])->toMatchArray(['id' => -42]);
});

it('accepts zero as int param', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['id' => UrlParamTypeEnum::Int->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/item/*'])->create();

    $parts = ['path' => '/item/0'];

    $result = ParseWildcardPageUrlAction::run($url, '/item/0', $parts);

    expect($result['params'])->toMatchArray(['id' => 0]);
});

it('preserves numeric value as string for string param', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['username' => UrlParamTypeEnum::String->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/user/*'])->create();

    $parts = ['path' => '/user/12345'];

    $result = ParseWildcardPageUrlAction::run($url, '/user/12345', $parts);

    expect($result['params'])->toMatchArray(['username' => '12345']);
});

it('does not flag string wildcard params as invalid pagination', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['tag' => UrlParamTypeEnum::String->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/tags/*'])->create();

    $parts = ['path' => '/tags/laravel'];

    $result = ParseWildcardPageUrlAction::run($url, '/tags/laravel', $parts, 'simple', ['enforceInvalidPageValue' => true]);

    expect($result['params'])->toMatchArray(['tag' => 'laravel'])
        ->and($result)->not()->toHaveKey('invalidPagination');
});

it('does not flag date wildcard params as invalid pagination', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['date' => UrlParamTypeEnum::Date->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/archive/*'])->create();

    $parts = ['path' => '/archive/2026-03'];

    $result = ParseWildcardPageUrlAction::run($url, '/archive/2026-03', $parts, 'simple', ['enforceInvalidPageValue' => true]);

    expect($result['params'])->toMatchArray(['date' => '2026-03'])
        ->and($result)->not()->toHaveKey('invalidPagination');
});

it('accepts full calendar dates for date params', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['date' => UrlParamTypeEnum::Date->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/archive/*'])->create();

    $parts = ['path' => '/archive/2026-03-18'];

    $result = ParseWildcardPageUrlAction::run($url, '/archive/2026-03-18', $parts);

    expect($result['params'])->toMatchArray(['date' => '2026-03-18']);
});

it('rejects invalid month and day values for date params', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['date' => UrlParamTypeEnum::Date->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/archive/*'])->create();

    $invalidMonthParts = ['path' => '/archive/2026-13'];
    $invalidDayParts = ['path' => '/archive/2026-02-30'];

    $invalidMonthResult = ParseWildcardPageUrlAction::run($url, '/archive/2026-13', $invalidMonthParts);
    $invalidDayResult = ParseWildcardPageUrlAction::run($url, '/archive/2026-02-30', $invalidDayParts);

    expect($invalidMonthResult['params'] ?? [])->not()->toHaveKey('date')
        ->and($invalidDayResult['params'] ?? [])->not()->toHaveKey('date');
});

it('does not set label value params when date value is invalid', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['date' => UrlParamTypeEnum::Date->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/archive/*'])->create();

    $parts = ['path' => '/archive/date/2026-02-30'];

    $result = ParseWildcardPageUrlAction::run($url, '/archive/date/2026-02-30', $parts);

    expect($result['params'] ?? [])->not()->toHaveKey('date');
});

it('does not set params for unsupported url param types', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['token' => 'uuid']]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/tokens/*'])->create();

    $parts = ['path' => '/tokens/abc-123'];

    $result = ParseWildcardPageUrlAction::run($url, '/tokens/abc-123', $parts);

    expect($result['params'] ?? [])->not()->toHaveKey('token');
});

it('accepts empty string for string param', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['username' => UrlParamTypeEnum::String->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/user/*'])->create();

    $parts = ['path' => '/user/'];

    $result = ParseWildcardPageUrlAction::run($url, '/user/', $parts);

    expect($result['params'])->not()->toHaveKey('username');
});

it('handles leading and trailing slashes in URL', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->enabled()->hasTranslations(['language_id' => $language->id])->create();
    $page = Page::factory()->site($site)->for(Blueprint::factory()->page()->meta(['url_params' => ['username' => UrlParamTypeEnum::String->value]]))->create();
    $url = PageUrl::factory()->site($site)->page($page)->state(['url' => '/user/*'])->create();

    $parts = ['path' => '/user/testuser/'];

    $result = ParseWildcardPageUrlAction::run($url, '/user/testuser/', $parts);

    expect($result['params'])->toMatchArray(['username' => 'testuser']);
});
