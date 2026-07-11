<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;

it('clears total sites cache on create/delete', function (): void {
    Cache::driver('array')->forever(CacheEnum::TotalSites->value, 123);

    $site = Site::factory()->createOne();

    expect(Cache::driver('array')->get(CacheEnum::TotalSites->value))->toBeNull();

    Cache::driver('array')->forever(CacheEnum::TotalSites->value, 123);

    $site->delete();

    expect(Cache::driver('array')->get(CacheEnum::TotalSites->value))->toBeNull();
});

it('flushes site-related caches on save', function (): void {
    $site = Site::factory()->createOne();

    // Prime various cache keys
    Cache::driver('array')->forever(CacheEnum::Site->value . '-default-fallback', true);
    Cache::driver('array')->forever(CacheEnum::AllSites->value . '-default', true);
    Cache::driver('array')->forever(CacheEnum::HasDefaultSite->value, true);

    $site->name = 'UpdatedName';
    $site->save();

    $registry = Cache::driver('array')->get('capell-core-cache-keys', []);

    expect($registry)->not()->toContain(CacheEnum::Site->value . '-default-fallback')
        ->and($registry)->not()->toContain(CacheEnum::AllSites->value . '-default')
        ->and($registry)->not()->toContain(CacheEnum::HasDefaultSite->value);
});
