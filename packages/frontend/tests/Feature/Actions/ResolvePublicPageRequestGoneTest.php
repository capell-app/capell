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
use Illuminate\Support\Facades\DB;

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

it('does not resolve a remembered missing URL again until a page changes', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();

    resolveGoneProbe($site, $language, '/soon-published');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $second = resolveGoneProbe($site, $language, '/soon-published');

    expect($second->shouldAbort404)->toBeTrue()
        ->and($queries)->toBe(0);

    $page = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->withTranslations($language, [], slug: 'soon-published')
        ->create();

    $url = (string) PageUrl::query()->where('pageable_id', $page->getKey())->value('url');

    expect($url)->toBe('/soon-published')
        ->and(resolveGoneProbe($site, $language, '/soon-published')->page)->not->toBeNull();
});

it('still answers 410 for a trashed page after its miss was probed repeatedly', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();
    $page = Page::factory()->site($site)->type($blueprint)->withTranslations($language, [], slug: 'gone-twice')->create();
    $url = (string) PageUrl::query()->where('pageable_id', $page->getKey())->value('url');
    $page->delete();

    resolveGoneProbe($site, $language, $url);

    expect(resolveGoneProbe($site, $language, $url)->gone)->toBeTrue();
});
