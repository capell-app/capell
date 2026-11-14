<?php

declare(strict_types=1);

use Capell\Core\Actions\Translations\HydratePageLanguagesAction;
use Capell\Core\Actions\Translations\ResolveLanguageFallbackChainAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Loader\PageLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('loads multilingual cold breadcrumb ancestors within the route query budget at any depth', function (int $depth, bool $fallback): void {
    $english = Language::factory()->english()->createOne();
    $german = Language::factory()->createOne(['code' => 'de', 'fallback_language_id' => $english->id]);
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $fallback ? $german->id : null]);
    $site = Site::factory()->language($english)->withTranslations([$english, $german, $french])->createOne();
    $site->load('siteDomains');

    $blueprint = Blueprint::factory()->page()->createOne();
    $parent = null;
    $expected = [];

    for ($level = 1; $level <= $depth + 1; $level++) {
        $languages = $fallback && $level % 2 === 1 ? ($level === 3 ? [$english] : [$english, $german]) : [$french];
        $factory = Page::factory()->site($site)->type($blueprint)->published()->withTranslations($languages);
        $parent = ($parent instanceof Page ? $factory->parent($parent) : $factory)->createOne();

        if ($level <= $depth) {
            $served = $languages[array_key_last($languages)];
            $expected[] = [$parent->id, $served->id, $parent->translations()->where('language_id', $served->id)->sole()->title];
        }
    }

    Cache::flush();
    resolve(PageModelCache::class)->flushLocalCache();
    app()->forgetInstance(HydratePageLanguagesAction::class);
    // Request resolution has already read the fallback map, as it does on public routes.
    ResolveLanguageFallbackChainAction::run($french);
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $ancestors = expectPresent(PageLoader::getPageAncestors(expectPresent($parent), $french, $site));
        $queries = count(DB::getQueryLog());
        $actual = $ancestors->map(static fn (Page $ancestor): array => [$ancestor->id, $ancestor->translation?->language_id, $ancestor->translation?->title])->all();

        foreach ($ancestors as $ancestor) {
            expect($ancestor->translation?->language?->id)->toBe($ancestor->translation?->language_id)
                ->and($ancestor->pageUrl?->language?->id)->toBe($ancestor->translation?->language_id)
                ->and($ancestor->pageUrl?->siteDomain?->language_id)->toBe($ancestor->translation?->language_id)
                ->and($ancestor->site?->id)->toBe($site->id)
                ->and($ancestor->blueprint?->id)->toBe($blueprint->id);
        }

        expect($actual)->toBe($expected)
            ->and(count(DB::getQueryLog()))->toBe($queries)
            ->and($queries)->toBe(5);
    } finally {
        DB::disableQueryLog();
    }
})->with([2, 4])->with([false, true]);

it('keeps the requested breadcrumb URL when fallback content has no usable URL', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->id]);
    $site = Site::factory()->language($english)->withTranslations([$english, $french])->createOne();
    $site->load('siteDomains');

    $parent = Page::factory()->site($site)->published()->withTranslations([$english])->createOne();
    $child = Page::factory()->parent($parent)->published()->withTranslations([$french])->createOne();
    $parent->pageUrls()->update(['status' => false]);
    $url = PageUrl::factory()->page($parent)->site($site)->language($french)->createOne(['url' => '/section-fr', 'type' => null, 'status' => true]);
    Cache::flush();
    resolve(PageModelCache::class)->flushLocalCache();

    $ancestor = expectPresent(PageLoader::getPageAncestors($child, $french, $site))->sole();

    expect($ancestor->translation?->language_id)->toBe($english->id)
        ->and($ancestor->translation?->language?->id)->toBe($english->id)
        ->and($ancestor->pageUrl?->id)->toBe($url->id)
        ->and($ancestor->pageUrl?->language?->id)->toBe($french->id)
        ->and($ancestor->pageUrl?->siteDomain?->language_id)->toBe($french->id);
});
