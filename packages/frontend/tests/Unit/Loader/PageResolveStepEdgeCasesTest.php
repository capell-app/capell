<?php

declare(strict_types=1);

use Capell\Core\Enums\UrlParamTypeEnum;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Kernel\Steps\PageResolveStep;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

// Helper: build the minimal FrontendWork with site+language+domain configured.
function makePageResolveWork(Site $site, Language $language, string $url, ?SiteDomain $domain = null): FrontendWork
{
    $resolvedDomain = $domain ?? SiteDomain::factory()->enabled()->state([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => '/',
    ])->create();

    $state = new FrontendState;
    $state->withSite($site)->withLanguage($language)->withDomain($resolvedDomain);
    $state->setEffectiveUrl($url);

    $request = Request::create('https://example.com' . $url);

    return new FrontendWork($request, $state);
}

// ─────────────────────────────────────────────────────────────
// Branch: no site or language in state → passes straight through
// ─────────────────────────────────────────────────────────────

it('passes through to next step when site is absent from state', function (): void {
    $state = new FrontendState;
    // Intentionally no site or language set.
    $request = Request::create('https://example.com/path');
    $work = new FrontendWork($request, $state);

    $nextWasCalled = false;
    $step = resolve(PageResolveStep::class);
    $step->handle($work, function (FrontendWork $passedWork) use (&$nextWasCalled, $work): FrontendWork {
        $nextWasCalled = true;
        expect($passedWork)->toBe($work);

        return $passedWork;
    });

    expect($nextWasCalled)->toBeTrue()
        ->and($work->getError())->toBeNull();
});

// ─────────────────────────────────────────────────────────────
// Branch: error page fallback when the requested page is not found
// ─────────────────────────────────────────────────────────────

it('falls back to error page when the requested url is not found', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $errorType = Blueprint::query()->where('key', 'error')->first()
        ?? Blueprint::factory()->page()->state(['key' => 'error'])->create();
    Page::factory()->site($site)->blueprint($errorType)->withTranslations($language)->create();

    $work = makePageResolveWork($site, $language, '/page-that-does-not-exist');

    $step = resolve(PageResolveStep::class);
    $nextWasCalled = false;
    $step->handle($work, function (FrontendWork $passedWork) use (&$nextWasCalled): FrontendWork {
        $nextWasCalled = true;

        return $passedWork;
    });

    // The error page was found and used, so `next` was called and no 404 error set.
    expect($nextWasCalled)->toBeTrue()
        ->and($work->getError())->toBeNull();
});

// ─────────────────────────────────────────────────────────────
// Branch: 404 when both requested page and error page are missing
// ─────────────────────────────────────────────────────────────

it('sets 404 when neither the requested page nor an error page can be found', function (): void {
    config()->set('capell-frontend.system_pages.auto_create_missing', false);

    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    // Deliberately no error page created.

    $work = makePageResolveWork($site, $language, '/also-not-found');

    $step = resolve(PageResolveStep::class);
    $result = $step->handle($work, fn (FrontendWork $passedWork): FrontendWork => $passedWork);

    expect($result->getError())->toBeArray()
        ->and($result->getError()['status'])->toBe(404);
});

it('sets 404 when wildcard fallback reaches a non-page url record', function (): void {
    config()->set('capell-frontend.system_pages.auto_create_missing', false);

    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();

    PageUrl::factory()
        ->manualRedirect()
        ->language($language)
        ->site($site)
        ->state([
            'url' => '/learn',
            'target_url' => '/tour',
        ])
        ->create();

    $work = makePageResolveWork($site, $language, '/learn/platform-map');

    $step = resolve(PageResolveStep::class);
    $result = $step->handle($work, fn (FrontendWork $passedWork): FrontendWork => $passedWork);

    expect($result->getError())->toBeArray()
        ->and($result->getError()['status'])->toBe(404);
});

// ─────────────────────────────────────────────────────────────
// Branch: revision page id set triggers the diagnostic log path
// and resolves the page correctly
// ─────────────────────────────────────────────────────────────

it('resolves page successfully when a revision page id matches a real page', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->site($site)->withTranslations($language)->create();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/revision-page'])->create();

    $domain = SiteDomain::factory()->enabled()->state([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => '/',
    ])->create();

    $state = new FrontendState;
    $state->withSite($site)->withLanguage($language)->withDomain($domain);
    $state->setEffectiveUrl('/revision-page');
    $state->setRevisionPageId($page->getKey());

    $request = Request::create('https://example.com/revision-page');
    $work = new FrontendWork($request, $state);

    $step = resolve(PageResolveStep::class);
    $nextWasCalled = false;
    $step->handle($work, function (FrontendWork $passedWork) use (&$nextWasCalled): FrontendWork {
        $nextWasCalled = true;

        return $passedWork;
    });

    expect($nextWasCalled)->toBeTrue()
        ->and($work->getError())->toBeNull()
        ->and($work->state->page())->not()->toBeNull();
});

it('falls back to the base page when a revision page id misses', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->site($site)->withTranslations($language)->create();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/revision-miss-page'])->create();

    $domain = SiteDomain::factory()->enabled()->state([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => '/',
    ])->create();

    $state = new FrontendState;
    $state->withSite($site)->withLanguage($language)->withDomain($domain);
    $state->setEffectiveUrl('/revision-miss-page');
    $state->setRevisionPageId((int) Page::query()->max('id') + 1);

    $request = Request::create('https://example.com/revision-miss-page');
    $work = new FrontendWork($request, $state);

    $step = resolve(PageResolveStep::class);
    $nextWasCalled = false;
    $step->handle($work, function (FrontendWork $passedWork) use (&$nextWasCalled): FrontendWork {
        $nextWasCalled = true;

        return $passedWork;
    });

    $resolvedPage = expectPresent($work->state->page());

    expect($nextWasCalled)->toBeTrue()
        ->and($work->getError())->toBeNull()
        ->and($work->state->page())->not()->toBeNull()
        ->and($resolvedPage->getKey())->toBe($page->getKey());
});

// ─────────────────────────────────────────────────────────────
// Branch: wildcard lookup skipped when already attempted
// ─────────────────────────────────────────────────────────────

it('does not attempt wildcard lookup twice when attribute flag is already set', function (): void {
    config()->set('capell-frontend.system_pages.auto_create_missing', false);

    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    // No error page, so 404 is expected.

    $domain = SiteDomain::factory()->enabled()->state([
        'site_id' => $site->id,
        'language_id' => $language->id,
        'domain' => 'example.com',
        'scheme' => 'https',
        'path' => '/',
    ])->create();

    $state = new FrontendState;
    $state->withSite($site)->withLanguage($language)->withDomain($domain);
    $state->setEffectiveUrl('/no-match/sub-path');

    $request = Request::create('https://example.com/no-match/sub-path');
    // Pre-set the "already attempted" flag so the wildcard branch is skipped.
    $request->attributes->set('_frontend_wildcard_attempted', true);

    $work = new FrontendWork($request, $state);

    $step = resolve(PageResolveStep::class);
    $result = $step->handle($work, fn (FrontendWork $passedWork): FrontendWork => $passedWork);

    // Without wildcard and without an error page, we get a 404.
    expect($result->getError())->toBeArray()
        ->and($result->getError()['status'])->toBe(404);
});

// ─────────────────────────────────────────────────────────────
// Branch: successful page resolution sets the page on work state
// ─────────────────────────────────────────────────────────────

it('resolves an existing page and calls next with the page set on work state', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->site($site)->withTranslations($language)->create();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/existing-page'])->create();

    $work = makePageResolveWork($site, $language, '/existing-page');

    $step = resolve(PageResolveStep::class);
    $nextWasCalled = false;
    $step->handle($work, function (FrontendWork $passedWork) use (&$nextWasCalled): FrontendWork {
        $nextWasCalled = true;

        return $passedWork;
    });

    $resolvedPage = expectPresent($work->state->page());

    expect($nextWasCalled)->toBeTrue()
        ->and($work->getError())->toBeNull()
        ->and($work->state->page())->not()->toBeNull()
        ->and($resolvedPage->getKey())->toBe($page->getKey());
});

it('applies route metadata to the work request instead of the global request', function (): void {
    config()->set('paginateroute.mode', 'simple');

    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()
        ->site($site)
        ->for(Blueprint::factory()->page()->meta(['url_params' => ['page' => UrlParamTypeEnum::Int->value]]))
        ->withTranslations($language)
        ->create();
    PageUrl::factory()->page($page)->language($language)->site($site)->state(['url' => '/news/*'])->create();

    $work = makePageResolveWork($site, $language, '/news/2');
    $workRoute = new Route(['GET'], '/placeholder', []);
    $workRoute->bind($work->request);

    $work->request->setRouteResolver(fn (): Route => $workRoute);

    $globalRequest = Request::create('https://example.com/global');
    $globalRoute = new Route(['GET'], '/global', []);
    $globalRoute->bind($globalRequest);

    $globalRequest->setRouteResolver(fn (): Route => $globalRoute);
    app()->instance('request', $globalRequest);

    resolve(PageResolveStep::class)->handle($work, fn (FrontendWork $passedWork): FrontendWork => $passedWork);

    expect($work->request->input('pageQuery'))->toBe('2')
        ->and($workRoute->uri())->toBe('/news/*')
        ->and($workRoute->parameter('page'))->toBe('2')
        ->and($workRoute->parameter('pageQuery'))->toBe('2')
        ->and($workRoute->parameter('pageQueryParams'))->toBe(['page' => 2])
        ->and($workRoute->parameter('pageSlug'))->toBe('/news')
        ->and($globalRequest->attributes->has('pageQuery'))->toBeFalse()
        ->and($globalRoute->uri())->toBe('global')
        ->and($globalRoute->parameter('pageQuery'))->toBeNull();
});

it('sets a redirect response for frontend page resolution redirects', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->site($site)->withTranslations($language)->create();
    PageUrl::factory()
        ->page($page)
        ->language($language)
        ->site($site)
        ->manualRedirect()
        ->state([
            'url' => '/redirect-source',
            'target_url' => '/redirect-target',
        ])
        ->create();

    $work = makePageResolveWork($site, $language, '/redirect-source');

    $nextWasCalled = false;
    $result = resolve(PageResolveStep::class)->handle($work, function (FrontendWork $passedWork) use (&$nextWasCalled): FrontendWork {
        $nextWasCalled = true;

        return $passedWork;
    });

    expect($nextWasCalled)->toBeFalse()
        ->and($result->getRedirect())->toBeInstanceOf(RedirectResponse::class)
        ->and($result->getRedirect()?->getTargetUrl())->toBe('http://localhost/redirect-target')
        ->and($result->getRedirect()?->getStatusCode())->toBe(301)
        ->and($work->getError())->toBeNull()
        ->and($work->state->page())->toBeNull();
});

it('sets a redirect response when a revision page id is present', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $basePage = Page::factory()->site($site)->withTranslations($language)->create();
    $revisionPage = Page::factory()->site($site)->withTranslations($language)->create();
    PageUrl::factory()
        ->page($basePage)
        ->language($language)
        ->site($site)
        ->manualRedirect()
        ->state([
            'url' => '/revision-redirect-source',
            'target_url' => '/revision-redirect-target',
        ])
        ->create();

    $work = makePageResolveWork($site, $language, '/revision-redirect-source');
    $work->state->setRevisionPageId($revisionPage->getKey());

    $nextWasCalled = false;
    $result = resolve(PageResolveStep::class)->handle($work, function (FrontendWork $passedWork) use (&$nextWasCalled): FrontendWork {
        $nextWasCalled = true;

        return $passedWork;
    });

    expect($nextWasCalled)->toBeFalse()
        ->and($result->getRedirect())->toBeInstanceOf(RedirectResponse::class)
        ->and($result->getRedirect()?->getTargetUrl())->toBe('http://localhost/revision-redirect-target')
        ->and($result->getRedirect()?->getStatusCode())->toBe(301)
        ->and($work->getError())->toBeNull()
        ->and($work->state->page())->toBeNull();
});
