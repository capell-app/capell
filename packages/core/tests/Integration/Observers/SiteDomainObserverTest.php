<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\ResolveSiteDomainAction;
use Capell\Core\Enums\CacheEnum;
use Capell\Core\Events\FrontendSurrogateKeysInvalidated;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Observers\SiteDomainObserver;
use Capell\Core\Support\Lookup\ArrayCache;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Capell\Frontend\Listeners\PurgeCdnCacheOnPageChangeListener;
use Capell\Frontend\Support\Cache\FragmentCache;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

it('flushes site-related caches when site domain changes', function (): void {
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->createOne(['site_id' => $site->id]);

    Cache::driver('array')->forever(CacheEnum::Site->value . '-default-fallback', true);

    $domain->domain = 'example.com';
    $domain->save();

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);
    expect($registry)->not()->toContain(CacheEnum::Site->value . '-default-fallback');
});

it('does not use a nested savepoint when creating multiple translated sites', function (): void {
    Site::factory()->withTranslations()->createOne();
    Site::factory()->withTranslations()->createOne();

    expect(Site::query()->count())->toBe(2);
});

it('defers domain invalidation until the outer transaction commits while flushing local lookups immediately', function (bool $restore): void {
    $domain = SiteDomain::factory()->createOne();
    if ($restore) {
        $domain->delete();
    }

    Event::fake([FrontendSurrogateKeysInvalidated::class]);
    $cache = resolve(ArrayCache::class);
    $lookupKey = CacheEnum::Site->value . '-domain-invalidation';
    expect($cache->remember($lookupKey, fn (): string => 'before'))->toBe('before');

    $connection = $domain->getConnection();
    $connection->transaction(function () use ($connection, $domain, $restore, $cache, $lookupKey): void {
        $connection->transaction(function () use ($domain, $restore): void {
            expect($restore ? $domain->restore() : $domain->update(['domain' => 'after.example.test']))->toBeTrue();
            Event::assertNotDispatched(FrontendSurrogateKeysInvalidated::class);
        });

        Event::assertNotDispatched(FrontendSurrogateKeysInvalidated::class);
        expect($cache->remember($lookupKey, fn (): string => 'after'))->toBe('after');
    });

    Event::assertDispatched(
        FrontendSurrogateKeysInvalidated::class,
        fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === ['site-' . $domain->site_id],
    );
    Event::assertDispatchedTimes(FrontendSurrogateKeysInvalidated::class, $restore ? 2 : 1);
})->with([false, true]);

it('discards domain invalidation when the enclosing transaction rolls back', function (bool $restore, bool $nestedRollback): void {
    $domain = SiteDomain::factory()->createOne(['domain' => 'before.example.test']);
    if ($restore) {
        $domain->delete();
    }

    Event::fake([FrontendSurrogateKeysInvalidated::class]);
    $connection = $domain->getConnection();
    $initialLevel = $connection->transactionLevel();
    try {
        $connection->beginTransaction();
        if ($nestedRollback) {
            $connection->beginTransaction();
        }

        expect($restore ? $domain->restore() : $domain->update(['domain' => 'after.example.test']))->toBeTrue();
        $connection->rollBack();
        if ($nestedRollback) {
            $connection->commit();
        }

        // A subsequent successful transaction must not dispatch discarded callbacks.
        $connection->transaction(fn (): bool => true);
        Event::assertNotDispatched(FrontendSurrogateKeysInvalidated::class);
        $stored = SiteDomain::withTrashed()->whereKey($domain->id)->firstOrFail();
        expect($stored->domain)->toBe('before.example.test')
            ->and($stored->trashed())->toBe($restore);
    } finally {
        $connection->rollBack($initialLevel);
    }
})->with([false, true])->with([false, true]);

it('removes a domain fragment refilled before commit through the frontend listener', function (bool $restore): void {
    $domain = SiteDomain::factory()->createOne(['domain' => 'before.example.test']);
    if ($restore) {
        $domain->delete();
    }

    $fragments = new FragmentCache(new Repository(new ArrayStore));
    app()->instance(FragmentCache::class, $fragments);
    Event::listen(FrontendSurrogateKeysInvalidated::class, [PurgeCdnCacheOnPageChangeListener::class, 'handleSurrogateKeys']);
    $surrogateKeys = ['site-' . $domain->site_id];
    expect($fragments->remember('domain-origin', fn (): string => 'before.example.test', surrogateKeys: $surrogateKeys))
        ->toBe('before.example.test');

    $domain->getConnection()->transaction(function () use ($domain, $restore, $fragments, $surrogateKeys): void {
        expect($restore ? $domain->restore() : $domain->update(['domain' => 'after.example.test']))->toBeTrue();

        // Model another request refilling an evicted fragment from the old committed state.
        $fragments->invalidateBySurrogateKey($surrogateKeys[0]);
        expect($fragments->remember('domain-origin', fn (): string => 'before.example.test', surrogateKeys: $surrogateKeys))
            ->toBe('before.example.test');
    });

    expect($fragments->remember('domain-origin', fn (): string => 'committed-domain', surrogateKeys: $surrogateKeys))
        ->toBe('committed-domain');
})->with([false, true]);

it('uses the domain connection for invalidation and dispatches immediately without a transaction', function (): void {
    config(['database.connections.domain_invalidation' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
    ]]);
    $domain = new SiteDomain(['site_id' => 42]);
    $domain->setConnection('domain_invalidation');

    $connection = $domain->getConnection();
    $observer = new SiteDomainObserver;
    Event::fake([FrontendSurrogateKeysInvalidated::class]);

    try {
        expect($connection->transactionLevel())->toBe(0);
        $observer->deleted($domain);
        Event::assertDispatchedTimes(FrontendSurrogateKeysInvalidated::class, 1);

        $connection->beginTransaction();
        $observer->deleted($domain);
        Event::assertDispatchedTimes(FrontendSurrogateKeysInvalidated::class, 1);
        $connection->commit();
        Event::assertDispatchedTimes(FrontendSurrogateKeysInvalidated::class, 2);
        Event::assertDispatched(
            FrontendSurrogateKeysInvalidated::class,
            fn (FrontendSurrogateKeysInvalidated $event): bool => $event->surrogateKeys === ['site-42'],
        );

        $attempts = collect();
        $connection->transaction(function () use ($observer, $domain, $attempts): void {
            $attempts->push(true);
            $observer->deleted($domain);
            throw_if($attempts->count() === 1, PDOException::class, 'database is locked');

            Event::assertDispatchedTimes(FrontendSurrogateKeysInvalidated::class, 2);
        }, attempts: 2);

        expect($attempts)->toHaveCount(2);
        Event::assertDispatchedTimes(FrontendSurrogateKeysInvalidated::class, 3);
    } finally {
        DB::purge('domain_invalidation');
    }
});

it('does not create missing site translations when a site domain is deleted', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->createOne();
    $domain = SiteDomain::factory()->language($language)->create(['site_id' => $site->id]);
    $site->translations()->where('language_id', $language->id)->delete();

    expect($site->translations()->where('language_id', $language->id)->exists())->toBeFalse();

    $domain->delete();

    expect($site->translations()->where('language_id', $language->id)->exists())->toBeFalse();
});

it('preserves wildcard schemes when checking a current domain claim', function (string $defaultScheme, string $ownedScheme, string $operation): void {
    config(['capell-frontend.default_scheme' => $defaultScheme]);
    $site = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $domain = SiteDomain::factory()->site($site)->make(['scheme' => null, 'domain' => 'owned.test', 'path' => '/claimed']);
    if ($operation === 'update') {
        $domain->fill(['scheme' => $defaultScheme, 'domain' => 'safe.test'])->save();
        $domain->fill(['scheme' => null, 'domain' => 'owned.test']);
    }

    if ($operation === 'restore') {
        $domain->save();
        $domain->delete();
    }

    SiteDomain::factory()->site($foreign)->create(['scheme' => $ownedScheme, 'domain' => 'owned.test', 'path' => '/']);
    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([$site->id]);

    test()->actingAs($actor);

    expect(fn () => $operation === 'restore' ? $domain->restore() : $domain->save())
        ->toThrow(ValidationException::class);
    expect(SiteDomain::query()->where('site_id', $site->id)->where('domain', 'owned.test')->exists())->toBeFalse();
})->with([['https', 'http'], ['http', 'https']])->with(['create', 'update', 'restore']);

it('protects origins resolved from non-canonical stored claims', function (?string $storedHost, string $storedScheme, string $claimHost, string $operation): void {
    $site = Site::factory()->createOne();
    $foreign = Site::factory()->createOne();
    $domain = SiteDomain::factory()->site($site)->make(['scheme' => 'https', 'domain' => $claimHost, 'path' => '/claimed']);
    if ($operation === 'update') {
        $domain->fill(['domain' => 'safe.test'])->save();
        $domain->fill(['domain' => $claimHost]);
    } elseif ($operation === 'restore') {
        $domain->save();
        $domain->delete();
    }

    $owned = SiteDomain::factory()->site($foreign)->create(['scheme' => 'https', 'domain' => $claimHost, 'path' => '/']);
    // Simulate legacy storage without canonicalising it through model observers.
    SiteDomain::query()->whereKey($owned->id)->update(['scheme' => $storedScheme, 'domain' => $storedHost]);
    $owned->refresh();
    expect($owned->getRawOriginal('scheme'))->toBe($storedScheme)
        ->and($owned->getRawOriginal('domain'))->toBe($storedHost);

    $resolution = ResolveSiteDomainAction::run(
        SiteDomainAddressing::targetFromUrl('https://' . $claimHost . '/claimed'),
        collect([$foreign->load('siteDomains')]),
    );
    expect($resolution?->siteDomain->id)->toBe($owned->id);

    $actor = User::factory()->createOne();
    $actor->assignedSiteIds = collect([$site->id]);

    test()->actingAs($actor);

    expect(fn () => $operation === 'restore' ? $domain->restore() : $domain->save())
        ->toThrow(ValidationException::class);
    expect(SiteDomain::query()->where('site_id', $site->id)->where('domain', $claimHost)->where('path', '/claimed')->exists())->toBeFalse();
    if ($operation === 'update') {
        expect($domain->fresh()->getRawOriginal('domain'))->toBe('safe.test');
    } elseif ($operation === 'restore') {
        expect($domain->fresh()->trashed())->toBeTrue();
    }

    expect($owned->fresh()->getRawOriginal('domain'))->toBe($storedHost)
        ->and($owned->fresh()->getRawOriginal('scheme'))->toBe($storedScheme);
})->with([
    'stored trailing dot' => ['owned.test.', 'https', 'owned.test'],
    'stored host case' => ['OWNED.TEST', 'https', 'owned.test'],
    'stored scheme case' => ['owned.test', 'HTTPS', 'owned.test'],
    'stored whitespace' => [' Owned.Test. ', ' HTTPS ', 'owned.test'],
    'stored IPv6 spelling' => ['[2001:0db8:0:0:0:0:0:1]', 'HTTPS', '[2001:db8::1]'],
    'stored wildcard host and scheme case' => [null, 'HTTPS', 'owned.test'],
])->with(['create', 'update', 'restore']);
