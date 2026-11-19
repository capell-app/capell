<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\LoadSiteRoutingCandidatesAction;
use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config(['cache.default' => 'array', 'capell.disable_cache' => false]);
    CapellCore::flushCache();
});

/** Resolve as a new request would: nothing carried over in this process's memory. */
function resolveRoutedSiteId(string $url): ?int
{
    CapellCore::flushLocalCache();

    $resolution = ResolveSiteDomainAction::run(
        SiteRequestTargetData::fromUrl($url),
        LoadSiteRoutingCandidatesAction::run(),
    );

    return $resolution?->siteDomainForRequest()->site_id;
}

it('reads the sites and domains from the shared cache after the first request', function (): void {
    $site = Site::factory()->createOne();
    SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'cached.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

    expect(resolveRoutedSiteId('https://cached.test/'))->toBe($site->id);

    CapellCore::flushLocalCache();
    DB::flushQueryLog();
    DB::enableQueryLog();

    $candidates = LoadSiteRoutingCandidatesAction::run();

    expect(DB::getQueryLog())->toBe([])
        ->and($candidates->pluck('id')->all())->toContain($site->id);
});

it('routes a changed domain host on the next request without a manual flush', function (): void {
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'old-host.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

    expect(resolveRoutedSiteId('https://old-host.test/'))->toBe($site->id);

    $domain->update(['domain' => 'new-host.test']);

    expect(resolveRoutedSiteId('https://new-host.test/'))->toBe($site->id)
        ->and(resolveRoutedSiteId('https://old-host.test/'))->toBeNull();
});

it('stops routing to a deleted domain on the next request', function (): void {
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'gone.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

    expect(resolveRoutedSiteId('https://gone.test/'))->toBe($site->id);

    $domain->delete();

    expect(resolveRoutedSiteId('https://gone.test/'))->toBeNull();
});

it('stops routing to a site once it becomes a preview site', function (): void {
    $site = Site::factory()->createOne();
    SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'preview.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

    expect(resolveRoutedSiteId('https://preview.test/'))->toBe($site->id);

    $site->update(['meta' => ['is_preview' => true]]);

    expect(resolveRoutedSiteId('https://preview.test/'))->toBeNull();
});

it('forgets candidates a concurrent request cached while the domain change was uncommitted', function (): void {
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'before-commit.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

    DB::transaction(function () use ($domain): void {
        $domain->update(['domain' => 'after-commit.test']);

        // Another request, outside this transaction, cannot see the new host yet and
        // caches candidates that do not route it.
        CapellCore::flushLocalCache();
        CapellCore::setToCache(CacheEnum::SiteRoutingCandidates->value, new Collection);
    });

    expect(resolveRoutedSiteId('https://after-commit.test/'))->toBe($site->id);
});
