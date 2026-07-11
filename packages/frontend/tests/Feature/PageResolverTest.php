<?php

declare(strict_types=1);

use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Actions\ResolvePublicPageRequestAction;
use Capell\Frontend\Data\PageResolutionData;
use Capell\Frontend\Data\PublicPageResolutionInputData;
use Symfony\Component\HttpFoundation\Response;

it('returns an aborting 404 resolution if no Url found', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $url = '/not-found';

    $result = resolvePublicPageForTest($site, $language, $url, abortMissingForBot: true);

    expect($result->page)->toBeNull()
        ->and($result->shouldAbort404)->toBeTrue();
});

it('returns Page if Url is not a redirect', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->site($site)->withTranslations($language)->create();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/page'])->create();

    $result = expectPresent(resolvePublicPageForTest($site, $language, '/page')->page);
    expect($result)->not()->toBeNull()
        ->and($result->getKey())->toBe($page->id);
});

it('does not share cached page urls for paths with the same slug key', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $nestedPage = Page::factory()->site($site)->withTranslations($language)->create();
    $dashedPage = Page::factory()->site($site)->withTranslations($language)->create();

    PageUrl::factory()->page($nestedPage)->language($language)->site($site)->state(['url' => '/foo/bar'])->create();
    PageUrl::factory()->page($dashedPage)->language($language)->site($site)->state(['url' => '/foo-bar'])->create();

    $nestedResult = expectPresent(resolvePublicPageForTest($site, $language, '/foo/bar')->page);
    $dashedResult = expectPresent(resolvePublicPageForTest($site, $language, '/foo-bar')->page);

    expect($nestedResult)->not()->toBeNull()
        ->and($nestedResult->getKey())->toBe($nestedPage->id)
        ->and($dashedResult)->not()->toBeNull()
        ->and($dashedResult->getKey())->toBe($dashedPage->id);
});

it('does not share cached page urls when dashed paths are resolved first', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $nestedPage = Page::factory()->site($site)->withTranslations($language)->create();
    $dashedPage = Page::factory()->site($site)->withTranslations($language)->create();

    PageUrl::factory()->page($nestedPage)->language($language)->site($site)->state(['url' => '/foo/bar'])->create();
    PageUrl::factory()->page($dashedPage)->language($language)->site($site)->state(['url' => '/foo-bar'])->create();

    $dashedResult = expectPresent(resolvePublicPageForTest($site, $language, '/foo-bar')->page);
    $nestedResult = expectPresent(resolvePublicPageForTest($site, $language, '/foo/bar')->page);

    expect($dashedResult)->not()->toBeNull()
        ->and($dashedResult->getKey())->toBe($dashedPage->id)
        ->and($nestedResult)->not()->toBeNull()
        ->and($nestedResult->getKey())->toBe($nestedPage->id);
});

it('does not share cached page urls for literal wildcard-shaped paths', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $wildcardShapePage = Page::factory()->site($site)->withTranslations($language)->create();
    $dashedPage = Page::factory()->site($site)->withTranslations($language)->create();

    PageUrl::factory()->page($wildcardShapePage)->language($language)->site($site)->state(['url' => '/foo/*'])->create();
    PageUrl::factory()->page($dashedPage)->language($language)->site($site)->state(['url' => '/foo-wild'])->create();

    $wildcardShapeResult = expectPresent(resolvePublicPageForTest($site, $language, '/foo/*')->page);
    $dashedResult = expectPresent(resolvePublicPageForTest($site, $language, '/foo-wild')->page);

    expect($wildcardShapeResult)->not()->toBeNull()
        ->and($wildcardShapeResult->getKey())->toBe($wildcardShapePage->id)
        ->and($dashedResult)->not()->toBeNull()
        ->and($dashedResult->getKey())->toBe($dashedPage->id);
});

it('loads page relations with correct language', function (): void {
    $otherLanguage = Language::factory()->createOne();
    $language = Language::factory()->createOne();
    $site = Site::factory()->language($language)->create();
    $page = Page::factory()->site($site)->create();
    Translation::factory()
        ->translatable($page)
        ->meta('slug', 'page')
        ->forEachSequence(
            ['language_id' => $otherLanguage->id],
            ['language_id' => $language->id],
        )
        ->create();

    $result = expectPresent(resolvePublicPageForTest($site, $language, '/page')->page);
    $pageUrl = expectPresent($result->pageUrl);

    expect($result)->toBeInstanceOf(Page::class)
        ->id->toBe($page->id)
        ->and($pageUrl)->toBeInstanceOf(PageUrl::class)->language_id->toBe($language->id);
});

it('does not resolve disabled urls by direct path', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->site($site)->create();
    PageUrl::factory()
        ->page($page)
        ->language($language)
        ->site($site)
        ->state(['url' => '/disabled-url', 'status' => false])
        ->create();

    $result = resolvePublicPageForTest($site, $language, '/disabled-url', abortMissingForBot: true);

    expect($result->page)->toBeNull()
        ->and($result->shouldAbort404)->toBeTrue();
});

it('does not resolve pages whose type is disabled by direct path', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $type = Blueprint::factory()->page()->state(['status' => false])->create();
    $page = Page::factory()->site($site)->type($type)->create();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/disabled-type'])->create();

    $result = resolvePublicPageForTest($site, $language, '/disabled-type', abortMissingForBot: true);

    expect($result->page)->toBeNull()
        ->and($result->shouldAbort404)->toBeTrue();
});

it('does not resolve pages whose type is inaccessible by direct path', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $type = Blueprint::factory()->page()->meta(['accessible' => false])->create();
    $page = Page::factory()->site($site)->type($type)->create();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/inaccessible-type'])->create();

    $result = resolvePublicPageForTest($site, $language, '/inaccessible-type', abortMissingForBot: true);

    expect($result->page)->toBeNull()
        ->and($result->shouldAbort404)->toBeTrue();
});

it('returns a redirect response if url is a redirect', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $targetPage = Page::factory()->site($site)->create();
    $redirectUrl = '/redirect';

    // Create the target Url (the destination of the redirect)
    PageUrl::factory()->page($targetPage)->language($language)->site($site)->state(['url' => '/target'])->create();

    $redirect = PageUrl::factory()->page($targetPage)->language($language)->site($site)->type(UrlTypeEnum::Redirect)->state(['url' => $redirectUrl])->create();

    $result = resolvePublicPageForTest($site, $language, $redirectUrl);

    expect($result->redirect?->getStatusCode())->toBe(Response::HTTP_MOVED_PERMANENTLY)
        ->and($redirect->refresh()->hit_count)->toBe(1);
});

it('returns a redirect response for manual redirects with target urls', function (): void {
    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();

    $redirect = PageUrl::factory()
        ->language($language)
        ->site($site)
        ->manualRedirect()
        ->state([
            'url' => '/manual-redirect',
            'target_url' => '/manual-target',
        ])
        ->create();

    $result = resolvePublicPageForTest($site, $language, '/manual-redirect');

    expect(parse_url((string) $result->redirect?->getTargetUrl(), PHP_URL_PATH))->toBe('/manual-target')
        ->and($redirect->refresh()->hit_count)->toBe(1);
});

function resolvePublicPageForTest(
    Site $site,
    Language $language,
    string $url,
    bool $abortMissingForBot = false,
): PageResolutionData {
    return ResolvePublicPageRequestAction::run(new PublicPageResolutionInputData(
        site: $site,
        language: $language,
        url: $url,
        abortMissingForBot: $abortMissingForBot,
    ));
}
