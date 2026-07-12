<?php

declare(strict_types=1);

use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Data\PageListingSpec;
use Capell\Frontend\Support\Cache\PageCacheInvalidator;
use Capell\Frontend\Support\Cache\PageListingCache;
use Capell\Frontend\Support\Cache\PageModelCache;
use Carbon\CarbonImmutable;

it('invalidates listing and model caches when a page is saved', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $type = Blueprint::factory()->page()->create();

    $page = Page::factory()
        ->site($site)
        ->blueprint($type)
        ->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'test-invalidate')
        ->create();

    // Warm both caches
    $modelCache = resolve(PageModelCache::class);
    $modelCache->get(Page::class, $page->id, $site, $language);

    $listingCache = resolve(PageListingCache::class);
    $spec = new PageListingSpec($language->id, $site->id, null, null, null, null, null, null, null, null, false, true, null, '');
    $listingCache->getIds($spec, fn (): array => [$page->id]);

    // Simulate a title change + save
    $page->translation->update(['title' => 'New Title']);

    resolve(PageCacheInvalidator::class)->onSaved($page);

    // After invalidation, fresh calls should reflect DB state
    $freshModel = expectPresent($modelCache->get(Page::class, $page->id, $site, $language));
    $freshTranslation = expectPresent($freshModel->translation);

    expect($freshTranslation->title)->toBe('New Title');

    $callCount = 0;
    $listingCache->getIds($spec, function () use (&$callCount, $page): array {
        $callCount++;

        return [$page->id];
    });

    expect($callCount)->toBe(1); // Loader was called → listing was invalidated
});
