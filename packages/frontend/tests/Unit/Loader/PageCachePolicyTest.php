<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheTime;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Frontend\Support\Loader\PageCachePolicy;

it('refuses to cache a never-cached page whose type was not loaded with it', function (): void {
    $type = Blueprint::factory()->page()->meta(['cache_time' => CacheTime::Never->value])->createOne();
    $page = Page::query()->whereKey(Page::factory()->type($type)->createOne()->getKey())->sole();

    expect($page->relationLoaded('blueprint'))->toBeFalse()
        ->and(PageCachePolicy::neverCached($page))->toBeTrue()
        ->and(PageCachePolicy::shouldCache($page))->toBeFalse();
});

it('refuses to cache a page whose type cannot be determined', function (): void {
    $page = Page::factory()->createOne();
    $page->setRelation('blueprint', null);

    expect($page->getAttribute('blueprint_id'))->not->toBeNull()
        ->and(PageCachePolicy::neverCached($page))->toBeTrue()
        ->and(PageCachePolicy::shouldCache($page))->toBeFalse();
});

it('caches a page whose type allows it, whether or not the type was loaded with it', function (): void {
    $type = Blueprint::factory()->page()->meta(['cache_time' => CacheTime::Daily->value])->createOne();
    $page = Page::query()->whereKey(Page::factory()->type($type)->createOne()->getKey())->sole();

    expect(PageCachePolicy::neverCached($page))->toBeFalse()
        ->and(PageCachePolicy::shouldCache($page))->toBeTrue()
        ->and(PageCachePolicy::shouldCache($page->load('blueprint')))->toBeTrue()
        ->and(PageCachePolicy::shouldCache(null))->toBeFalse();
});
