<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Loader\PageLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Theme, breadcrumbs widget, SEO schema and blog contributors each ask for the
// same trail during one public render; only the first may reach the database.
it('reads a breadcrumb trail once per request however many contributors ask for it', function (): void {
    $english = Language::factory()->english()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english])->createOne();
    $site->load('siteDomains');

    $root = Page::factory()->site($site)->published()->withTranslations([$english])->createOne();
    $section = Page::factory()->parent($root)->site($site)->published()->withTranslations([$english])->createOne();
    $leaf = Page::factory()->parent($section)->site($site)->published()->withTranslations([$english])->createOne();

    Cache::flush();
    resolve(PageModelCache::class)->flushLocalCache();
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $first = expectPresent(PageLoader::getPageAncestors($leaf, $english, $site));
        $afterFirst = count(DB::getQueryLog());

        for ($call = 0; $call < 4; $call++) {
            $again = expectPresent(PageLoader::getPageAncestors($leaf, $english, $site));
            expect($again->pluck('id')->all())->toBe($first->pluck('id')->all());
        }

        expect($first->pluck('id')->all())->toBe([$root->id, $section->id])
            ->and($afterFirst)->toBeGreaterThan(0)
            ->and(count(DB::getQueryLog()))->toBe($afterFirst);
    } finally {
        DB::disableQueryLog();
    }
});

it('reads the trail again after a page is saved in the same request', function (): void {
    $english = Language::factory()->english()->createOne();
    $site = Site::factory()->language($english)->withTranslations([$english])->createOne();
    $site->load('siteDomains');

    $root = Page::factory()->site($site)->published()->withTranslations([$english])->createOne();
    $leaf = Page::factory()->parent($root)->site($site)->published()->withTranslations([$english])->createOne();

    expect(expectPresent(PageLoader::getPageAncestors($leaf, $english, $site))->pluck('id')->all())->toBe([$root->id]);

    $root->forceFill(['visible_until' => now()->subDay()])->save();
    Cache::flush();
    resolve(PageModelCache::class)->flushLocalCache();

    expect(PageLoader::getPageAncestors($leaf, $english, $site))->toBeNull();
});
