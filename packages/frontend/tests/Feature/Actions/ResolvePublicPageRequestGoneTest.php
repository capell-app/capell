<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\ResolvePublicPageRequestAction;
use Capell\Frontend\Data\PageResolutionData;
use Capell\Frontend\Data\PublicPageResolutionInputData;

function resolveGoneProbe(Site $site, Language $language, string $url): PageResolutionData
{
    return ResolvePublicPageRequestAction::run(new PublicPageResolutionInputData(
        site: $site,
        language: $language,
        url: $url,
        abortMissingForBot: true,
    ));
}

it('answers 410 for the URL of a trashed page and serves it again once restored', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();
    $page = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($language, [], slug: 'retired')
        ->create();

    $url = (string) PageUrl::query()->where('pageable_id', $page->getKey())->value('url');

    expect(resolveGoneProbe($site, $language, $url)->page)->not->toBeNull()
        ->and(resolveGoneProbe($site, $language, $url)->gone)->toBeFalse();

    $page->delete();

    $trashed = resolveGoneProbe($site, $language, $url);

    expect($trashed->gone)->toBeTrue()
        ->and($trashed->page)->toBeNull();

    $page->refresh()->restore();

    $restored = resolveGoneProbe($site, $language, $url);

    expect($restored->gone)->toBeFalse()
        ->and($restored->page)->not->toBeNull();
});

it('keeps answering 404 for a URL that never belonged to a page', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();

    $resolution = resolveGoneProbe($site, $language, '/never-existed');

    expect($resolution->gone)->toBeFalse()
        ->and($resolution->shouldAbort404)->toBeTrue();
});
