<?php

declare(strict_types=1);

use Capell\Core\Actions\Redirects\AddRedirectUrlAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;

it('adds a redirect url', function (): void {
    $language = Language::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['language_id' => $language->id]);
    $page = Page::factory()->site($domain->site)->create();

    AddRedirectUrlAction::run($page, $language, '/new');

    expect($page->pageUrls)->toHaveCount(1)
        ->and($page->pageUrls->first()->url)->toBe('/new');
});

it('rejects invalid url', function (): void {
    $page = Page::factory()->createOne();
    $language = Language::factory()->createOne();

    expect(fn (): mixed => AddRedirectUrlAction::run($page, $language, 'bad'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects redirects for languages not configured on the page site', function (): void {
    $language = Language::factory()->createOne();
    $otherLanguage = Language::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['language_id' => $language->id]);
    $page = Page::factory()->site($domain->site)->create();

    expect(fn (): mixed => AddRedirectUrlAction::run($page, $otherLanguage, '/new'))
        ->toThrow(InvalidArgumentException::class);
});
