<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard;

use Capell\Admin\Concerns\CachesDashboardQuery;
use Capell\Admin\Tests\Feature\Dashboard\Fixtures\CachesDashboardQueryTestClass;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    $this->freezeTime();
});

it('reuses a query result until the default five-minute cache expires', function (): void {
    $widget = new CachesDashboardQueryTestClass;

    $executions = 0;
    $query = function () use (&$executions): int {
        return ++$executions;
    };

    expect($widget->cacheQueryResult($query, 'dashboard:query'))->toBe(1);

    $this->travel(299)->seconds();

    expect($widget->cacheQueryResult($query, 'dashboard:query'))->toBe(1)
        ->and($executions)->toBe(1);

    $this->travel(1)->second();

    expect($widget->cacheQueryResult($query, 'dashboard:query'))->toBe(2);
});

it('expires a query result at the configured widget lifetime', function (): void {
    $widget = new class
    {
        use CachesDashboardQuery;

        protected function dashboardCacheTtl(): int
        {
            return 60;
        }
    };

    expect($widget->cacheQueryResult(fn (): string => 'original result', 'dashboard:query'))
        ->toBe('original result');

    $this->travel(59)->seconds();

    expect($widget->cacheQueryResult(fn (): string => 'too early', 'dashboard:query'))
        ->toBe('original result');

    $this->travel(1)->second();

    expect($widget->cacheQueryResult(fn (): string => 'refreshed result', 'dashboard:query'))
        ->toBe('refreshed result');
});

it('keeps values isolated by key when a widget clears one query result', function (): void {
    $widget = new CachesDashboardQueryTestClass;
    $details = ['total' => 42, 'status' => ['ready' => true]];
    $summary = (object) ['name' => 'Summary'];

    expect($widget->cacheQueryResult(fn (): array => $details, 'dashboard:details'))->toBe($details)
        ->and($widget->cacheQueryResult(fn (): object => $summary, 'dashboard:summary'))->toEqual($summary)
        ->and($widget->cacheQueryResult(fn (): int => 42, 'dashboard:total'))->toBe(42)
        ->and($widget->cacheQueryResult(fn (): bool => false, 'dashboard:flag'))->toBeFalse();

    $widget->clearCacheForWidget('dashboard:details');

    expect($widget->cacheQueryResult(fn (): array => [], 'dashboard:details'))->toBe([])
        ->and($widget->cacheQueryResult(fn (): object => (object) [], 'dashboard:summary'))->toEqual($summary)
        ->and($widget->cacheQueryResult(fn (): int => 0, 'dashboard:total'))->toBe(42)
        ->and($widget->cacheQueryResult(fn (): bool => true, 'dashboard:flag'))->toBeFalse();
});
