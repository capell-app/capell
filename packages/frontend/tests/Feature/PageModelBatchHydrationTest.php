<?php

declare(strict_types=1);

use Capell\Core\Actions\Translations\HydratePageLanguagesAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Support\Cache\PageModelCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('hydrates a cold listing with a constant number of queries and the same data as individual reads', function (): void {
    $english = Language::factory()->english()->createOne();
    $german = Language::factory()->createOne(['code' => 'de', 'fallback_language_id' => $english->id]);
    $site = Site::factory()->language($english)->withTranslations([$english, $german])->createOne();
    $site->load('siteDomains');

    $blueprint = Blueprint::factory()->page()->createOne();

    $makePages = function (int $count) use ($site, $blueprint, $english, $german): array {
        $pages = [];

        for ($index = 0; $index < $count; $index++) {
            $page = Page::factory()->site($site)->type($blueprint)->published()->withTranslations([$english, $german])->createOne();
            $image = Media::factory()->model($page)->image()->createOne();
            Translation::factory()->translatable($image)->language($english)->createOne(['meta' => ['alt' => 'Listing image']]);
            $pages[] = $page->id;
        }

        return $pages;
    };

    $cache = resolve(PageModelCache::class);
    $cold = function () use ($cache): void {
        Cache::flush();
        $cache->flushLocalCache();
        app()->forgetInstance(HydratePageLanguagesAction::class);
    };
    $measure = function (array $ids) use ($cache, $site, $german, $cold): array {
        $cold();
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $models = $cache->getMany(Page::class, $ids, $site, $german);
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        return [$models, $queries];
    };

    $few = $makePages(2);
    [, $fewQueries] = $measure($few);
    $many = [...$few, ...$makePages(4)];
    [$batched, $manyQueries] = $measure($many);

    $cold();
    $individual = array_map(static fn (int $id): array => expectPresent($cache->get(Page::class, $id, $site, $german))->toArray(), $many);

    expect($manyQueries)->toBe($fewQueries)
        ->and($batched->pluck('id')->all())->toBe($many)
        ->and($batched->toArray())->toEqual($individual);
});
