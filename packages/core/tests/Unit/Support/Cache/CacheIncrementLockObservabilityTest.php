<?php

declare(strict_types=1);

use Capell\Core\Support\Cache\CapellCacheManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

afterEach(function (): void {
    config([
        'cache.default' => 'array',
        'capell.disable_cache' => false,
        'capell.cache_tag' => 'capell-app',
    ]);

    Cache::purge('lockless');
    Cache::flush();
});

/**
 * A store that increments non-atomically and cannot hand out locks, which is the
 * shape of a file or database cache whose lock backend has failed.
 */
function capellLocklessCacheStore(): Repository
{
    return new Repository(new class extends ArrayStore
    {
        public function lock($name, $seconds = 0, $owner = null): never
        {
            throw new RuntimeException('Lock backend unavailable.');
        }
    });
}

it('records and logs an increment that had to run without its lock', function (): void {
    Cache::extend('lockless', capellLocklessCacheStore(...));
    config([
        'cache.default' => 'lockless',
        'cache.stores.lockless.driver' => 'lockless',
        'capell.disable_cache' => false,
    ]);
    Cache::purge('lockless');

    Log::spy();

    $manager = new CapellCacheManager;
    $manager->invalidateCachePattern('increment-lock-observability-*');

    $diagnostics = $manager->runtimeDiagnostics();

    expect($diagnostics->unlockedIncrementCount)->toBeGreaterThanOrEqual(1);

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->atLeast()
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'ran unlocked')
                && $context['store'] === 'lockless'
                && $context['reason'] === 'Lock backend unavailable.'
                && preg_match('/^[a-f0-9]{16}$/', (string) $context['key_hash']) === 1);
});

it('leaves the unlocked increment counter untouched when the lock is available', function (): void {
    config([
        'cache.default' => 'array',
        'capell.disable_cache' => false,
    ]);

    Log::spy();

    $manager = new CapellCacheManager;
    $manager->invalidateCachePattern('increment-lock-healthy-*');

    expect($manager->runtimeDiagnostics()->unlockedIncrementCount)->toBe(0);

    Log::getFacadeRoot()->shouldNotHaveReceived('warning');
});
