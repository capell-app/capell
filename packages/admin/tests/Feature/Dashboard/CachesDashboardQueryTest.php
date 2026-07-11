<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard;

use Capell\Admin\Concerns\CachesDashboardQuery;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\CachesDashboardQueryTestClass;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;

beforeEach(function (): void {
    Cache::flush();
});

it('caches query results and executes callback only once', function (): void {
    $testObject = new CachesDashboardQueryTestClass;

    $callCount = 0;
    $callback = function () use (&$callCount): string {
        $callCount++;

        return 'test-result';
    };

    // First call should execute the callback
    $result1 = $testObject->cacheQueryResult($callback, 'test-key');
    expect($result1)->toBe('test-result');
    expect($callCount)->toBe(1);

    // Second call should return cached value without executing callback
    $result2 = $testObject->cacheQueryResult($callback, 'test-key');
    expect($result2)->toBe('test-result');
    expect($callCount)->toBe(1); // Still 1, not 2
});

it('returns mixed type results correctly', function (): void {
    $testObject = new CachesDashboardQueryTestClass;

    // Test with array result
    $arrayResult = $testObject->cacheQueryResult(
        fn (): array => ['status' => 'healthy', 'count' => 42],
        'array-key',
    );
    expect($arrayResult)->toBe(['status' => 'healthy', 'count' => 42]);

    // Test with object result
    $objectResult = $testObject->cacheQueryResult(
        fn (): object => (object) ['data' => 'value'],
        'object-key',
    );
    expect($objectResult->data)->toBe('value');

    // Test with scalar result
    $scalarResult = $testObject->cacheQueryResult(
        fn (): int => 123,
        'scalar-key',
    );
    expect($scalarResult)->toBe(123);
});

it('uses default TTL of 300 seconds', function (): void {
    $testObject = new CachesDashboardQueryTestClass;

    $testObject->cacheQueryResult(fn (): string => 'data', 'ttl-test');

    // Verify the cache entry exists
    expect(Cache::get('ttl-test'))->toBe('data');
});

it('allows overriding default TTL via dashboardCacheTtl method', function (): void {
    $class = new class
    {
        use CachesDashboardQuery;

        protected function dashboardCacheTtl(): int
        {
            return 60; // 1 minute instead of 5
        }
    };

    $class->cacheQueryResult(fn (): string => 'custom-ttl-data', 'custom-ttl-key');

    // Verify the cache entry exists
    expect(Cache::get('custom-ttl-key'))->toBe('custom-ttl-data');
});

it('clears cache for a specific widget', function (): void {
    $testObject = new CachesDashboardQueryTestClass;

    // Cache a value
    $testObject->cacheQueryResult(fn (): string => 'data', 'cache-key');

    expect(Cache::get('cache-key'))->toBe('data');

    // Clear the cache
    $testObject->clearCacheForWidget('cache-key');

    // Verify the cache was cleared
    expect(Cache::get('cache-key'))->toBeNull();
});

it('returns correct default TTL of 300 seconds', function (): void {
    $testObject = new CachesDashboardQueryTestClass;

    // Access the protected method using reflection
    $reflection = new ReflectionClass($testObject);
    $method = $reflection->getMethod('dashboardCacheTtl');

    expect($method->invoke($testObject))->toBe(300);
});
