<?php

declare(strict_types=1);

use Capell\Admin\Support\Redirects\RedirectHealthRequestCache;
use Capell\Core\Models\PageUrl;
use Illuminate\Support\Facades\DB;

it('negative-caches a missing redirect health snapshot for one request', function (): void {
    $pageUrl = new PageUrl;
    $pageUrl->id = 999_999;

    $cache = resolve(RedirectHealthRequestCache::class);
    DB::flushQueryLog();
    DB::enableQueryLog();

    expect($cache->for($pageUrl))->toBeNull()
        ->and($cache->for($pageUrl))->toBeNull();

    $queries = collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains($query['query'], 'redirect_health_snapshots'));

    expect($queries)->toHaveCount(1);
});
