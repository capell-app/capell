<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\LoadSiteRoutingCandidatesAction;
use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

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

it('stops a long-lived worker routing a host another process removed', function (): void {
    $site = Site::factory()->createOne();
    SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'worker.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

    $resolveInThisWorker = static fn (): ?int => ResolveSiteDomainAction::run(
        SiteRequestTargetData::fromUrl('https://worker.test/'),
        LoadSiteRoutingCandidatesAction::run(),
    )?->siteDomainForRequest()->site_id;

    expect($resolveInThisWorker())->toBe($site->id);

    // Another process deletes the domain: its observer forgets the shared entry, which
    // cannot reach this worker's memory.
    DB::table('site_domains')->where('domain', 'worker.test')->delete();
    Cache::store()->flush();

    expect($resolveInThisWorker())->toBeNull();
});

it('forgets candidates cached inside a transaction that rolls back', function (): void {
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'kept.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

    try {
        DB::transaction(function () use ($domain): void {
            $domain->update(['domain' => 'discarded.test']);
            // A resolution inside the transaction caches the uncommitted host.
            resolveRoutedSiteId('https://discarded.test/');

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(resolveRoutedSiteId('https://kept.test/'))->toBe($site->id)
        ->and(resolveRoutedSiteId('https://discarded.test/'))->toBeNull();
});

it('re-reads the cache generation for each queued job, so a worker stops routing a host removed after another process flushed the cache', function (): void {
    $cachePath = storage_path('framework/testing/routing-worker-cache-' . uniqid());
    File::ensureDirectoryExists($cachePath);
    // A store without tags: a full flush moves the namespace generation instead of dropping entries.
    config(['cache.default' => 'file', 'cache.stores.file.driver' => 'file', 'cache.stores.file.path' => $cachePath]);
    Cache::purge('file');

    $workerManager = resolve(CapellCacheManager::class);
    $workerRequest = resolve('request');
    $inAnotherProcess = static function (Closure $work) use ($workerManager, $workerRequest): void {
        app()->instance(CapellCacheManager::class, new CapellCacheManager);
        app()->instance('request', Request::create('https://admin.example.test/'));

        try {
            $work();
        } finally {
            app()->instance(CapellCacheManager::class, $workerManager);
            app()->instance('request', $workerRequest);
        }
    };
    $resolveInWorker = static fn (): ?int => ResolveSiteDomainAction::run(
        SiteRequestTargetData::fromUrl('https://worker-generation.test/'),
        LoadSiteRoutingCandidatesAction::run(),
    )?->siteDomainForRequest()->site_id;

    try {
        $site = Site::factory()->createOne();
        SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'worker-generation.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

        expect($resolveInWorker())->toBe($site->id);

        // Another process flushes the cache, then deletes the domain: its observer forgets
        // the entry under the new generation only.
        $inAnotherProcess(static fn () => CapellCore::flushCache());
        $inAnotherProcess(static fn () => SiteDomain::query()->where('domain', 'worker-generation.test')->firstOrFail()->delete());

        event(new JobProcessing('redis', new FakeJob));

        expect($resolveInWorker())->toBeNull();
    } finally {
        Cache::purge('file');
        File::deleteDirectory($cachePath);
    }
});

it('does not cache candidates read before a domain change committed', function (): void {
    $site = Site::factory()->createOne();
    SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'racing.test', 'scheme' => 'https', 'path' => null, 'status' => true]);

    $committed = false;
    DB::listen(static function (QueryExecuted $query) use (&$committed): void {
        if ($committed || ! str_contains($query->sql, 'site_domains')) {
            return;
        }

        // Another process commits the removal after this request read the old rows,
        // and forgets the entry before this request writes it.
        $committed = true;
        DB::table('site_domains')->where('domain', 'racing.test')->delete();
        LoadSiteRoutingCandidatesAction::forget();
    });

    expect(resolveRoutedSiteId('https://racing.test/'))->toBe($site->id)
        ->and($committed)->toBeTrue()
        ->and(resolveRoutedSiteId('https://racing.test/'))->toBeNull();
});

it('does not cache candidates read before a domain change when the change revision is then flushed away', function (): void {
    $site = Site::factory()->createOne();
    SiteDomain::factory()->createOne(['site_id' => $site->id, 'domain' => 'flushed-race.test', 'scheme' => 'https', 'path' => null, 'status' => true]);
    // No revision is stored yet, as after any earlier flush or eviction.
    Cache::store()->flush();

    $committed = false;
    DB::listen(static function (QueryExecuted $query) use (&$committed): void {
        if ($committed || ! str_contains($query->sql, 'site_domains')) {
            return;
        }

        // Another process commits the removal and forgets the list, then the revision it
        // moved is lost (a tag flush or an eviction) before this request writes.
        $committed = true;
        DB::table('site_domains')->where('domain', 'flushed-race.test')->delete();
        LoadSiteRoutingCandidatesAction::forget();
        Cache::store()->flush();
    });

    expect(resolveRoutedSiteId('https://flushed-race.test/'))->toBe($site->id)
        ->and($committed)->toBeTrue()
        ->and(resolveRoutedSiteId('https://flushed-race.test/'))->toBeNull();
});
