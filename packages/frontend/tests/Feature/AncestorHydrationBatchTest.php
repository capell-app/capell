<?php

declare(strict_types=1);

use Capell\Core\Actions\Translations\HydratePageLanguagesAction;
use Capell\Core\Actions\Translations\ResolveLanguageFallbackChainAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Loader\PageLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('hydrates cold ancestors with constant queries and the same canonical fallback data as individual reads', function (int $depth, bool $fallback): void {
    $english = Language::factory()->english()->createOne();
    $german = Language::factory()->createOne(['code' => 'de', 'fallback_language_id' => $english->id]);
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $fallback ? $german->id : null]);
    $site = Site::factory()->language($english)->withTranslations([$english, $german, $french])->createOne();
    $site->load('siteDomains');

    $blueprint = Blueprint::factory()->page()->createOne();
    $ancestors = [];
    $parent = null;

    foreach (range(1, $depth + 1) as $level) {
        // The nearest fallback wins over English; the exact translation wins over both.
        $languages = $fallback && $level % 2 === 1
            ? ($level === 3 ? [$english] : [$english, $german])
            : [$english, $german, $french];
        $factory = Page::factory()->site($site)->type($blueprint)->published()->withTranslations($languages);
        $page = ($parent instanceof Page ? $factory->parent($parent) : $factory)->createOne();
        $image = Media::factory()->model($page)->image()->createOne();
        Translation::factory()->translatable($image)->language($english)->createOne(['meta' => ['alt' => 'Ancestor image']]);

        if ($level <= $depth) {
            $ancestors[] = $page;
        }

        $parent = $page;
    }

    $cache = resolve(PageModelCache::class);
    Cache::flush();
    $cache->flushLocalCache();
    app()->forgetInstance(HydratePageLanguagesAction::class);
    $individual = [];

    foreach ($ancestors as $ancestor) {
        $individual[] = expectPresent($cache->get(Page::class, $ancestor->id, $site, $french))->toArray();
    }

    Cache::flush();
    $cache->flushLocalCache();
    app()->forgetInstance(HydratePageLanguagesAction::class);
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $batched = $cache->getAncestors(array_map(static fn (Page $ancestor): int => $ancestor->id, $ancestors), $site, $french);
        $queries = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($batched->pluck('id')->all())->toBe(array_map(static fn (Page $page): int => $page->id, $ancestors))
        ->and($batched->toArray())->toEqual($individual)
        ->and($queries)->toBe($fallback ? 11 : 10);

    foreach ($batched as $index => $ancestor) {
        $served = $fallback && $index % 2 === 0 ? ($index === 2 ? $english : $german) : $french;
        expect($ancestor->translation?->language_id)->toBe($served->id)
            ->and($ancestor->translation?->language?->id)->toBe($served->id)
            ->and($ancestor->pageUrl?->language_id)->toBe($served->id)
            ->and($ancestor->pageUrl?->language?->id)->toBe($served->id)
            ->and($ancestor->pageUrl?->siteDomain?->language_id)->toBe($served->id)
            ->and($ancestor->site?->id)->toBe($site->id);
    }
})->with([2, 4])->with([false, true]);

it('keeps a requested language URL when the fallback translation has no usable URL', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->id]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $site->load('siteDomains');

    $parent = Page::factory()->site($site)->published()->withTranslations([$english])->createOne();
    $parent->pageUrls()->update(['status' => false]);
    $url = PageUrl::factory()->page($parent)->site($site)->language($french)->createOne(['url' => '/section-fr', 'type' => null, 'status' => true]);
    $cache = resolve(PageModelCache::class);
    Cache::flush();
    $cache->flushLocalCache();
    $individual = expectPresent($cache->get(Page::class, $parent->id, $site, $french))->toArray();
    Cache::flush();
    $cache->flushLocalCache();

    $ancestor = $cache->getAncestors([$parent->id], $site, $french)->sole();

    expect($ancestor->toArray())->toEqual($individual)
        ->and($ancestor->translation?->language?->id)->toBe($english->id)
        ->and($ancestor->pageUrl?->id)->toBe($url->id)
        ->and($ancestor->pageUrl?->language?->id)->toBe($french->id)
        ->and($ancestor->pageUrl?->siteDomain?->language_id)->toBe($french->id);
});

it('preserves ordered cache hits while dropping foreign unpublished and missing ancestors', function (): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->language($language)->withTranslations([$language])->createOne();
    $site->load('siteDomains');

    $first = Page::factory()->site($site)->published()->withTranslations([$language])->createOne();
    $second = Page::factory()->site($site)->published()->withTranslations([$language])->createOne();
    $unpublished = Page::factory()->site($site)->pending()->withTranslations([$language])->createOne();
    $foreign = Page::factory()->published()->withTranslations([$language])->createOne();
    $cache = resolve(PageModelCache::class);
    Cache::flush();
    $cache->flushLocalCache();
    $cache->get(Page::class, $second->id, $site, $language);

    $result = $cache->getAncestors([$second->id, $foreign->id, $unpublished->id, 999999, $first->id], $site, $language);

    expect($result->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($cache->getAncestors([], $site, $language))->toBeEmpty();

    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $warm = $cache->getAncestors([$first->id, $second->id], $site, $language);
        expect($warm->pluck('id')->all())->toBe([$first->id, $second->id])
            ->and(DB::getQueryLog())->toBeEmpty();
    } finally {
        DB::disableQueryLog();
    }
});

it('batches the two cold ancestors used by an anonymous blog breadcrumb', function (int $depth): void {
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->language($language)->withTranslations([$language])->createOne();
    $site->load('siteDomains');

    $blueprint = Blueprint::factory()->page()->createOne();
    $parent = null;

    for ($level = 1; $level <= $depth + 1; $level++) {
        $factory = Page::factory()->site($site)->type($blueprint)->published()->withTranslations([$language]);
        $parent = ($parent instanceof Page ? $factory->parent($parent) : $factory)->createOne();
    }

    Cache::flush();
    resolve(PageModelCache::class)->flushLocalCache();
    app()->forgetInstance(HydratePageLanguagesAction::class);
    // Anonymous request resolution has already read the language map; the pages are cold.
    ResolveLanguageFallbackChainAction::run($language);
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $ancestors = PageLoader::getPageAncestors(expectPresent($parent), $language, $site);
        $queries = count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }

    expect($ancestors)->toHaveCount($depth)
        ->and($queries)->toBe(4);
})->with([2, 4]);
