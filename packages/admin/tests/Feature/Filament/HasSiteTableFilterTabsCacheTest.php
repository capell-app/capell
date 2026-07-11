<?php

declare(strict_types=1);

use Capell\Admin\Enums\CacheEnum;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\get;

beforeEach(function (): void {
    Cache::flush();
});

it('invalidates the pages site tabs cache when a page is saved', function (): void {
    $cacheKey = CacheEnum::siteTabs(Site::class, 'pages');
    Cache::put($cacheKey, collect(['stale-marker']), 3600);

    Page::factory()->createOne();

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('invalidates the pages site tabs cache when a page is deleted', function (): void {
    $page = Page::factory()->createOne();

    $cacheKey = CacheEnum::siteTabs(Site::class, 'pages');
    Cache::put($cacheKey, collect(['stale-marker']), 3600);

    $page->delete();

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('invalidates the layouts site tabs cache when a layout is saved', function (): void {
    $cacheKey = CacheEnum::siteTabs(Site::class, 'layouts');
    Cache::put($cacheKey, collect(['stale-marker']), 3600);

    Layout::factory()->createOne();

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('invalidates the layouts site tabs cache when a layout is deleted', function (): void {
    $layout = Layout::factory()->createOne();

    $cacheKey = CacheEnum::siteTabs(Site::class, 'layouts');
    Cache::put($cacheKey, collect(['stale-marker']), 3600);

    $layout->delete();

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('rebuilds the layouts site tabs cache when the cached value cannot be restored', function (): void {
    test()->actingAsAdmin();

    Site::factory()->count(2)->create();

    $cacheKey = CacheEnum::siteTabs(Site::class, 'layouts');
    Cache::put($cacheKey, unserialize('O:35:"Capell\\Legacy\\CachedSitesCollection":0:{}'), 3600);

    get(LayoutResource::getUrl())->assertOk();

    expect(Cache::get($cacheKey))
        ->toBeInstanceOf(Collection::class)
        ->each->toBeInstanceOf(Site::class);
});
