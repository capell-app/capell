<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Support\CapellCoreHelper;
use Illuminate\Support\Facades\Cache;

it('has expected cache enum cases and flushes keys by enum prefix', function (): void {
    expect(CacheEnum::HasFoundationTheme)->toBeInstanceOf(CacheEnum::class)
        ->and(CacheEnum::Site)->toBeInstanceOf(CacheEnum::class)
        ->and(CacheEnum::RelationExists)->toBeInstanceOf(CacheEnum::class)
        ->and(CacheEnum::UpgradeLock->value)->toBe('capell:upgrade');

    $prefixes = [
        CacheEnum::HasFoundationTheme->value . '-dummy',
        CacheEnum::Site->value . '-dummy',
    ];

    foreach ($prefixes as $key) {
        $result = (function (string $cacheKey): string {
            $ref = new class
            {
                public function __invoke(): string
                {
                    return 'x';
                }
            };
            Cache::driver('array')->forever($cacheKey, 'x');
            $registryKey = 'capell-core-cache-keys';
            $registry = Cache::driver('array')->get($registryKey, []);
            if (! in_array($cacheKey, $registry, true)) {
                $registry[] = $cacheKey;
                Cache::driver('array')->forever($registryKey, $registry);
            }

            return $ref();
        })($key);
        expect($result)->toBe('x');
    }

    $flushed = CapellCoreHelper::flushCache([CacheEnum::HasFoundationTheme, CacheEnum::Site]);
    expect($flushed)->toBeGreaterThanOrEqual(2);

    foreach ($prefixes as $key) {
        expect(Cache::driver('array')->get($key))->toBeNull();
    }
});
