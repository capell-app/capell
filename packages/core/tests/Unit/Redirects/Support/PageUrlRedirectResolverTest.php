<?php

declare(strict_types=1);

use Capell\Core\Data\RedirectDecisionData;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Support\Redirects\PageUrlRedirectHitRecorder;
use Capell\Core\Support\Redirects\PageUrlRedirectResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

function ensurePageUrlHitColumns(): void
{
    Schema::table('page_urls', function (Blueprint $table): void {
        if (! Schema::hasColumn('page_urls', 'hit_count')) {
            $table->unsignedInteger('hit_count')->default(0)->after('is_manual');
        }

        if (! Schema::hasColumn('page_urls', 'last_hit_at')) {
            $table->timestamp('last_hit_at')->nullable()->after('hit_count');
        }
    });
}

it('returns manual target urls with configured status code', function (): void {
    ensurePageUrlHitColumns();

    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $redirect = PageUrl::factory()
        ->manualRedirect()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/old',
            'target_url' => '/new',
            'status_code' => RedirectStatusCodeEnum::Temporary,
        ])
        ->create();

    $resolver = new PageUrlRedirectResolver(resolve(PageUrlRedirectHitRecorder::class));

    $decision = expectPresent($resolver->resolve($site, $language, '/old', pageUrl: $redirect));

    expect($decision)->toBeInstanceOf(RedirectDecisionData::class)
        ->and($decision->targetUrl)->toBe('/new')
        ->and($decision->statusCode)->toBe(302)
        ->and($redirect->refresh()->hit_count)->toBe(1);
});

it('returns current page url for automatic redirects', function (): void {
    ensurePageUrlHitColumns();

    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->site($site)->create();
    PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->state(['url' => '/new'])
        ->create();
    $redirect = PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->type(UrlTypeEnum::Redirect)
        ->state(['url' => '/old'])
        ->create();

    $resolver = new PageUrlRedirectResolver(resolve(PageUrlRedirectHitRecorder::class));

    $decision = expectPresent($resolver->resolve($site, $language, '/old', pageUrl: $redirect));

    expect($decision)->toBeInstanceOf(RedirectDecisionData::class)
        ->and($decision->targetUrl)->toBe('/new')
        ->and($decision->statusCode)->toBe(301)
        ->and($redirect->refresh()->hit_count)->toBe(1);
});

it('returns null for non redirect page urls', function (): void {
    ensurePageUrlHitColumns();

    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $page = Page::factory()->site($site)->create();
    $pageUrl = PageUrl::factory()
        ->page($page)
        ->site($site)
        ->language($language)
        ->state(['url' => '/page'])
        ->create();

    $resolver = new PageUrlRedirectResolver(resolve(PageUrlRedirectHitRecorder::class));

    expect($resolver->resolve($site, $language, '/page', pageUrl: $pageUrl))->toBeNull()
        ->and($pageUrl->refresh()->hit_count)->toBe(0);
});

it('uses the wildcard home redirect as a fallback and preserves the requested path and query', function (): void {
    ensurePageUrlHitColumns();
    request()->server->set('QUERY_STRING', 'ref=old&ref=legacy');

    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    $redirect = PageUrl::factory()
        ->manualRedirect()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/*',
            'target_url' => 'https://new.example',
            'status_code' => RedirectStatusCodeEnum::Permanent,
        ])
        ->create();

    $resolver = new PageUrlRedirectResolver(resolve(PageUrlRedirectHitRecorder::class));

    $decision = expectPresent($resolver->resolve($site, $language, '/deep/path'));

    expect($decision)->toBeInstanceOf(RedirectDecisionData::class)
        ->and($decision->targetUrl)->toBe('https://new.example/deep/path?ref=old&ref=legacy')
        ->and($decision->statusCode)->toBe(301)
        ->and($redirect->refresh()->hit_count)->toBe(1);
});

it('prefers an exact redirect over the wildcard home redirect', function (): void {
    ensurePageUrlHitColumns();

    $site = Site::factory()->createOne();
    $language = Language::factory()->createOne();
    PageUrl::factory()
        ->manualRedirect()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/*',
            'target_url' => 'https://new.example',
        ])
        ->create();
    $exactRedirect = PageUrl::factory()
        ->manualRedirect()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/deep/path',
            'target_url' => '/specific-target',
        ])
        ->create();

    $resolver = new PageUrlRedirectResolver(resolve(PageUrlRedirectHitRecorder::class));

    $decision = expectPresent($resolver->resolve($site, $language, '/deep/path'));

    expect($decision)->toBeInstanceOf(RedirectDecisionData::class)
        ->and($decision->targetUrl)->toBe('/specific-target')
        ->and($exactRedirect->refresh()->hit_count)->toBe(1);
});
