<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\Navigation\AdminNavigationBadgeCountCache;
use Capell\Core\Models\Page;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('caches resource navigation badge counts for the request', function (): void {
    Page::factory()->createOne();

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'pages')) {
            $queries++;
        }
    });

    $cache = resolve(AdminNavigationBadgeCountCache::class);
    $first = $cache->count(PageResource::class);
    $second = $cache->count(PageResource::class);

    expect($first)->toBe($second)
        ->and($first)->toBeGreaterThan(0)
        ->and($queries)->toBe(1);
});
