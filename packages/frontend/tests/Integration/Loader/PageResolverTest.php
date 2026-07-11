<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ResolvePublicPageRequestAction;
use Capell\Frontend\Data\PublicPageResolutionInputData;

it('resolves page by url for site and language', function (): void {
    $site = Site::factory()->withTranslations()->create();
    $page = Page::factory()->site($site)->home()->withTranslations(slug: '/')->create();

    /** @var Language $language */
    $language = $site->language;

    $resolved = expectPresent(ResolvePublicPageRequestAction::run(new PublicPageResolutionInputData(
        site: $site,
        language: $language,
        url: '/',
    ))->page);

    expect($resolved)->toBeInstanceOf(Page::class)
        ->and($resolved->getKey())->toBe($page->id);
});
