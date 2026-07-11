<?php

declare(strict_types=1);

use Capell\Core\Actions\Redirects\CreateAutomaticRedirectAction;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;

it('creates a permanent automatic redirect from an old url to a page', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne();

    $created = CreateAutomaticRedirectAction::run($page, $language, '/old', '/new');

    expect($created)->toBeTrue()
        ->and(PageUrl::query()->where('url', '/old')->first())
        ->type->toBe(UrlTypeEnum::Redirect)
        ->is_manual->toBeFalse()
        ->status_code->toBe(RedirectStatusCodeEnum::Permanent);
});

it('uses the configured automatic redirect status code', function (): void {
    config()->set('redirects.auto_redirects.status_code', RedirectStatusCodeEnum::Temporary->value);
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne();

    CreateAutomaticRedirectAction::run($page, $language, '/old', '/new');

    expect(PageUrl::query()->where('url', '/old')->first())
        ->status_code->toBe(RedirectStatusCodeEnum::Temporary);
});

it('does not overwrite an existing manual redirect', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne();
    PageUrl::factory()
        ->manualRedirect()
        ->site($page->site)
        ->language($language)
        ->state(['url' => '/old', 'target_url' => '/manual-target'])
        ->create();

    $created = CreateAutomaticRedirectAction::run($page, $language, '/old', '/new');

    expect($created)->toBeFalse()
        ->and(PageUrl::query()->where('url', '/old')->count())->toBe(1)
        ->and(PageUrl::query()->where('url', '/old')->first())
        ->is_manual->toBeTrue()
        ->target_url->toBe('/manual-target');
});

it('does not create a redirect when source and current url match', function (): void {
    $language = Language::factory()->createOne();
    $page = Page::factory()->createOne();

    $created = CreateAutomaticRedirectAction::run($page, $language, '/same', '/same');

    expect($created)->toBeFalse()
        ->and(PageUrl::query()->where('url', '/same')->exists())->toBeFalse();
});
