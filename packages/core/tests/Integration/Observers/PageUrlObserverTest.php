<?php

declare(strict_types=1);

use Capell\Core\Enums\CacheEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Exceptions\PageUrlCollisionException;
use Capell\Core\Exceptions\PageUrlSiteMismatchException;
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

it('still rejects a typed URL colliding with its own canonical URL', function (UrlTypeEnum $type): void {
    $page = Page::factory()->createOne();
    $canonical = PageUrl::factory()->page($page)->site($page->site)->language($page->site->language)
        ->createOne(['url' => '/canonical', 'type' => null]);
    $typedUrl = PageUrl::factory()->page($page)->site($page->site)->language($page->site->language)
        ->createOne(['url' => '/previous', 'type' => $type]);

    expect(fn (): bool => $typedUrl->fill(['url' => '/canonical'])->save())
        ->toThrow(PageUrlCollisionException::class)
        ->and($canonical->fresh()->url)->toBe('/canonical')
        ->and($typedUrl->fresh()->url)->toBe('/previous');
})->with([UrlTypeEnum::Alias, UrlTypeEnum::Redirect]);

it('refuses to re-point an existing URL at a page of another site and keeps the row unchanged', function (): void {
    $pageA = Page::factory()->createOne();
    $pageB = Page::factory()->createOne();
    $url = PageUrl::factory()->page($pageA)->site($pageA->site)->language($pageA->site->language)->createOne(['url' => '/stays-put']);

    expect(fn (): bool => $url->fill(['pageable_id' => $pageB->getKey()])->save())
        ->toThrow(PageUrlSiteMismatchException::class)
        ->and($url->fresh()->pageable_id)->toBe($pageA->getKey());
});

it('refuses a manual URL created for a page of another site', function (): void {
    $pageA = Page::factory()->createOne();
    $pageB = Page::factory()->createOne();

    $create = fn (): PageUrl => PageUrl::query()->create([
        'site_id' => $pageA->site_id,
        'language_id' => $pageA->site->language_id,
        'pageable_type' => $pageB->getMorphClass(),
        'pageable_id' => $pageB->getKey(),
        'url' => '/manual-cross-site',
        'is_manual' => true,
        'status' => true,
    ]);

    expect($create)->toThrow(PageUrlSiteMismatchException::class)
        ->and(PageUrl::query()->where('url', '/manual-cross-site')->exists())->toBeFalse();
});

it('refuses a disabled URL that moves to a page of another site', function (): void {
    $pageA = Page::factory()->createOne();
    $pageB = Page::factory()->createOne();
    $url = PageUrl::factory()->page($pageA)->site($pageA->site)->language($pageA->site->language)
        ->createOne(['url' => '/disabled-url', 'status' => false]);

    expect(fn (): bool => $url->fill(['pageable_id' => $pageB->getKey()])->save())
        ->toThrow(PageUrlSiteMismatchException::class);
});

it('still lets a URL move to another page of the same site', function (): void {
    $pageA = Page::factory()->createOne();
    $sibling = Page::factory()->site($pageA->site)->createOne();
    $url = PageUrl::factory()->page($pageA)->site($pageA->site)->language($pageA->site->language)->createOne(['url' => '/moves']);

    $url->fill(['pageable_id' => $sibling->getKey()])->save();

    expect($url->fresh()->pageable_id)->toBe($sibling->getKey());
});
