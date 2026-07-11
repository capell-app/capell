<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;

it('validates site ID match on creating', function (): void {
    $site1 = Site::factory()->createOne();
    $site2 = Site::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site1->id]);

    $pageUrl = new PageUrl([
        'pageable_id' => $page->getKey(),
        'pageable_type' => $page->getMorphClass(),
        'site_id' => $site2->id,
        'language_id' => $site1->language_id,
        'url' => '/x',
    ]);

    expect(fn () => $pageUrl->save())->toThrow(Exception::class);
});

it('flushes caches on saved/deleted and deletes its cache on delete', function (): void {
    $site = Site::factory()->createOne();
    $page = Page::factory()->createOne(['site_id' => $site->id]);

    $pageUrl = PageUrl::factory()->createOne([
        'pageable_id' => $page->getKey(),
        'pageable_type' => $page->getMorphClass(),
        'site_id' => $site->id,
        'language_id' => $site->language_id,
        'url' => '/home',
    ]);

    Cache::driver('array')->forever(CacheEnum::FirstPageByTypeForSite->value, true);

    $pageUrl->delete();

    $registryAfter = Cache::driver('array')->get('capell-core-cache-keys', []);

    expect($registryAfter)->not()->toContain(CacheEnum::FirstPageByTypeForSite->value);
});
