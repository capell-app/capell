<?php

declare(strict_types=1);

use Capell\Core\Actions\Redirects\ValidateRedirectAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;

it('allows relative and http redirect targets', function (string $targetUrl): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $result = ValidateRedirectAction::run(
        sourceUrl: '/old',
        targetUrl: $targetUrl,
        siteId: $site->getKey(),
        languageId: $language->getKey(),
    );

    expect($result['errors'])->toBeEmpty();
})->with([
    ['/new'],
    ['https://example.test/new'],
    ['http://example.test/new'],
]);

it('rejects unsafe redirect targets', function (string $targetUrl): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $result = ValidateRedirectAction::run(
        sourceUrl: '/old',
        targetUrl: $targetUrl,
        siteId: $site->getKey(),
        languageId: $language->getKey(),
    );

    expect($result['errors'])->toContain(__('capell::message.redirect_target_invalid'));
})->with([
    ['//evil.test/path'],
    ['javascript:alert(1)'],
    ["https://example.test/\nLocation: https://evil.test"],
    ['not-a-url'],
]);
