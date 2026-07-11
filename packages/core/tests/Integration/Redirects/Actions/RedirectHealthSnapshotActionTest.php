<?php

declare(strict_types=1);

use Capell\Core\Actions\Redirects\RefreshRedirectHealthSnapshotAction;
use Capell\Core\Actions\Redirects\RefreshRedirectHealthSnapshotsAction;
use Capell\Core\Database\Factories\LanguageFactory;
use Capell\Core\Database\Factories\SiteFactory;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\RedirectHealthSnapshot;
use Carbon\CarbonInterface;

it('stores redirect health for a redirect chain', function (): void {
    $language = LanguageFactory::new()->createOne();
    $site = SiteFactory::new()->recycle($language)->language($language)->withTranslations($language)->create();

    $targetRedirect = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/next',
            'target_url' => '/final',
            'type' => UrlTypeEnum::Redirect,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'status' => true,
        ])
        ->create();

    $redirect = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/old',
            'target_url' => '/next',
            'type' => UrlTypeEnum::Redirect,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'status' => true,
        ])
        ->create();

    $snapshot = RefreshRedirectHealthSnapshotAction::run($redirect);

    expect($snapshot)->toBeInstanceOf(RedirectHealthSnapshot::class)
        ->and($snapshot->page_url_id)->toBe($redirect->id)
        ->and($snapshot->has_chain)->toBeTrue()
        ->and($snapshot->has_loop)->toBeFalse()
        ->and($snapshot->warning_count)->toBeGreaterThan(0)
        ->and($targetRedirect->exists)->toBeTrue();
});

it('does not mark non-chain warnings as redirect chains', function (): void {
    $language = LanguageFactory::new()->createOne();
    $site = SiteFactory::new()->recycle($language)->language($language)->withTranslations($language)->create();

    PageUrl::withoutEvents(fn (): PageUrl => PageUrl::factory()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/conflicting-source',
            'target_url' => '/automatic-target',
            'type' => UrlTypeEnum::Redirect,
            'is_manual' => false,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'status' => true,
        ])
        ->create());

    $redirect = PageUrl::withoutEvents(fn (): PageUrl => PageUrl::factory()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/conflicting-source',
            'target_url' => '/manual-target',
            'type' => UrlTypeEnum::Redirect,
            'is_manual' => true,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'status' => true,
        ])
        ->create());

    $snapshot = RefreshRedirectHealthSnapshotAction::run($redirect);

    expect($snapshot)->toBeInstanceOf(RedirectHealthSnapshot::class)
        ->and($snapshot->has_chain)->toBeFalse()
        ->and($snapshot->warning_count)->toBeGreaterThan(0);
});

it('refreshes stale health snapshots for active broken redirects only', function (): void {
    $language = LanguageFactory::new()->createOne();
    $site = SiteFactory::new()->recycle($language)->language($language)->withTranslations($language)->create();

    $brokenRedirect = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/broken',
            'target_url' => '/broken',
            'type' => UrlTypeEnum::Redirect,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'status' => true,
        ])
        ->create();

    $inactiveRedirect = PageUrl::factory()
        ->site($site)
        ->language($language)
        ->state([
            'url' => '/inactive',
            'target_url' => '/still-broken',
            'type' => UrlTypeEnum::Redirect,
            'status_code' => RedirectStatusCodeEnum::Permanent,
            'status' => false,
        ])
        ->create();

    $staleComputedAt = now()->subMonth()->startOfSecond();

    RedirectHealthSnapshot::query()->create([
        'page_url_id' => $brokenRedirect->getKey(),
        'source_url' => '/stale-source',
        'target_url' => '/stale-target',
        'has_chain' => false,
        'has_loop' => false,
        'warning_count' => 0,
        'error_count' => 0,
        'computed_at' => $staleComputedAt,
    ]);

    RedirectHealthSnapshot::query()->create([
        'page_url_id' => $inactiveRedirect->getKey(),
        'source_url' => '/inactive',
        'target_url' => '/still-broken',
        'has_chain' => false,
        'has_loop' => false,
        'warning_count' => 0,
        'error_count' => 0,
        'computed_at' => $staleComputedAt,
    ]);

    $result = RefreshRedirectHealthSnapshotsAction::run(chunkSize: 1);

    $refreshedSnapshot = RedirectHealthSnapshot::query()
        ->where('page_url_id', $brokenRedirect->getKey())
        ->firstOrFail();
    $inactiveSnapshot = RedirectHealthSnapshot::query()
        ->where('page_url_id', $inactiveRedirect->getKey())
        ->firstOrFail();
    $refreshedComputedAt = $refreshedSnapshot->getAttribute('computed_at');
    $inactiveComputedAt = $inactiveSnapshot->getAttribute('computed_at');

    assert($refreshedComputedAt instanceof CarbonInterface);
    assert($inactiveComputedAt instanceof CarbonInterface);

    expect($result)->toBe(['refreshed' => 1])
        ->and($refreshedSnapshot->getAttribute('source_url'))->toBe('/broken')
        ->and($refreshedSnapshot->getAttribute('target_url'))->toBe('/broken')
        ->and($refreshedSnapshot->getAttribute('has_loop'))->toBeTrue()
        ->and($refreshedSnapshot->getAttribute('error_count'))->toBeGreaterThan(0)
        ->and($refreshedComputedAt->greaterThan($staleComputedAt))->toBeTrue()
        ->and($inactiveComputedAt->equalTo($staleComputedAt))->toBeTrue()
        ->and(RedirectHealthSnapshot::query()->count())->toBe(2);
});
