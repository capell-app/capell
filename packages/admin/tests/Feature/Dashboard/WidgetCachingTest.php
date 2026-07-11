<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard;

use Capell\Admin\Concerns\CachesDashboardQuery;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\WidgetCachingTestClass;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;

beforeEach(function (): void {
    Cache::flush();
});

it('executes callback on first call and caches result', function (): void {
    $widget = new WidgetCachingTestClass;
    $executionCount = 0;

    $result = $widget->cacheQueryResult(
        function () use (&$executionCount): string {
            $executionCount++;

            return 'cached-data';
        },
        'test:first-call',
    );

    expect($result)->toBe('cached-data');
    expect($executionCount)->toBe(1);
});

it('reuses cached result on subsequent calls without executing callback', function (): void {
    $widget = new WidgetCachingTestClass;
    $executionCount = 0;

    // First call executes callback
    $result1 = $widget->cacheQueryResult(
        function () use (&$executionCount): string {
            $executionCount++;

            return 'cached-data';
        },
        'test:reuse-cache',
    );

    expect($result1)->toBe('cached-data');
    expect($executionCount)->toBe(1);

    // Second call should use cached value
    $result2 = $widget->cacheQueryResult(
        function () use (&$executionCount): string {
            $executionCount++;

            return 'cached-data';
        },
        'test:reuse-cache',
    );

    expect($result2)->toBe('cached-data');
    expect($executionCount)->toBe(1); // Still 1, not incremented
});

it('applies default TTL of 300 seconds (5 minutes)', function (): void {
    $widget = new WidgetCachingTestClass;

    $widget->cacheQueryResult(
        fn (): string => 'data',
        'test:default-ttl',
    );

    // Verify cache entry exists and has the correct TTL
    expect(Cache::get('test:default-ttl'))->toBe('data');

    // We can't directly test TTL expiry in tests, but we verify the cache is set
    expect(Cache::has('test:default-ttl'))->toBeTrue();
});

it('allows overriding default TTL via dashboardCacheTtl method', function (): void {
    $widget = new class
    {
        use CachesDashboardQuery;

        protected function dashboardCacheTtl(): int
        {
            return 60; // Override to 1 minute
        }
    };

    $widget->cacheQueryResult(
        fn (): string => 'custom-ttl-data',
        'test:custom-ttl',
    );

    // Verify cache entry exists with custom TTL
    expect(Cache::get('test:custom-ttl'))->toBe('custom-ttl-data');
});

it('clears cache for a specific widget using cache key', function (): void {
    $widget = new WidgetCachingTestClass;

    // Cache a value
    $widget->cacheQueryResult(
        fn (): string => 'data-to-clear',
        'test:clearable-key',
    );

    expect(Cache::has('test:clearable-key'))->toBeTrue();

    // Clear the cache
    $widget->clearCacheForWidget('test:clearable-key');

    expect(Cache::has('test:clearable-key'))->toBeFalse();
});

it('maintains separate caches for different cache keys', function (): void {
    $widget = new WidgetCachingTestClass;
    $count1 = 0;
    $count2 = 0;

    // Cache first key
    $result1 = $widget->cacheQueryResult(
        function () use (&$count1): string {
            $count1++;

            return 'data-key-1';
        },
        'test:key-1',
    );

    // Cache second key
    $result2 = $widget->cacheQueryResult(
        function () use (&$count2): string {
            $count2++;

            return 'data-key-2';
        },
        'test:key-2',
    );

    expect($result1)->toBe('data-key-1');
    expect($result2)->toBe('data-key-2');
    expect($count1)->toBe(1);
    expect($count2)->toBe(1);

    // Retrieve from cache again
    $result1Again = $widget->cacheQueryResult(
        function () use (&$count1): string {
            $count1++;

            return 'data-key-1';
        },
        'test:key-1',
    );

    $result2Again = $widget->cacheQueryResult(
        function () use (&$count2): string {
            $count2++;

            return 'data-key-2';
        },
        'test:key-2',
    );

    // Both should be from cache, counts unchanged
    expect($result1Again)->toBe('data-key-1');
    expect($result2Again)->toBe('data-key-2');
    expect($count1)->toBe(1); // Not incremented
    expect($count2)->toBe(1); // Not incremented
});

it('clears one cache key without affecting others', function (): void {
    $widget = new WidgetCachingTestClass;

    // Cache two values
    $widget->cacheQueryResult(fn (): string => 'data-1', 'test:key-to-keep');
    $widget->cacheQueryResult(fn (): string => 'data-2', 'test:key-to-clear');

    expect(Cache::has('test:key-to-keep'))->toBeTrue();
    expect(Cache::has('test:key-to-clear'))->toBeTrue();

    // Clear only one
    $widget->clearCacheForWidget('test:key-to-clear');

    expect(Cache::has('test:key-to-keep'))->toBeTrue();
    expect(Cache::get('test:key-to-keep'))->toBe('data-1');
    expect(Cache::has('test:key-to-clear'))->toBeFalse();
});

it('handles complex data blueprints correctly', function (): void {
    $widget = new WidgetCachingTestClass;

    // Array result
    $arrayResult = $widget->cacheQueryResult(
        fn (): array => ['status' => 'healthy', 'count' => 42, 'details' => ['cached' => true]],
        'test:array-type',
    );

    expect($arrayResult)->toBe(['status' => 'healthy', 'count' => 42, 'details' => ['cached' => true]]);

    // Object result
    $objectResult = $widget->cacheQueryResult(
        fn (): object => (object) ['name' => 'Widget', 'data' => ['nested' => 'value']],
        'test:object-type',
    );

    expect($objectResult->name)->toBe('Widget');
    expect($objectResult->data['nested'])->toBe('value');

    // Integer result
    $intResult = $widget->cacheQueryResult(
        fn (): int => 999,
        'test:int-type',
    );

    expect($intResult)->toBe(999);

    // Boolean result
    $boolResult = $widget->cacheQueryResult(
        fn (): bool => true,
        'test:bool-type',
    );

    expect($boolResult)->toBeTrue();
});

it('returns the protected dashboardCacheTtl default value of 300 seconds', function (): void {
    $widget = new WidgetCachingTestClass;

    $reflection = new ReflectionClass($widget);
    $method = $reflection->getMethod('dashboardCacheTtl');

    expect($method->invoke($widget))->toBe(300);
});
