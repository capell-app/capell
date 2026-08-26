<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Actions\PurgeCdnCacheByPageAction;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Cache\PageModelCache;
use Carbon\CarbonImmutable;

it('invalidates cached page delivery after a real rollback', function (): void {
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->createOne();
    $blueprint = Blueprint::factory()->page()->createOne();
    $page = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->published(CarbonImmutable::parse('2020-01-01'))
        ->withTranslations($language, [
            'title' => 'First version',
            'content' => '<p>First version</p>',
        ], slug: 'rollback-cache-invalidation')
        ->createOne();

    $page->load(['translations', 'pageUrls']);
    $page->save();

    $targetVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation = $page->translations()->where('language_id', $language->getKey())->firstOrFail();
    $translation->forceFill([
        'title' => 'Second version',
        'content' => '<p>Second version</p>',
    ])->save();
    $page->load(['translations', 'pageUrls']);
    $page->save();

    $cache = resolve(PageModelCache::class);
    /** @var Page $cachedPage */
    $cachedPage = expectPresent($cache->get(Page::class, $page->id, $site, $language));
    $cacheKey = CacheEnum::pageModel(Page::class, $page->id, $site->id, $language->id);

    expect($cachedPage->translation?->title)->toBe('Second version')
        ->and($cache->getFromCache($cacheKey))->toBeInstanceOf(Page::class);

    PurgeCdnCacheByPageAction::shouldRun()
        ->once()
        ->withArgs(static fn (Page $invalidatedPage): bool => $invalidatedPage->is($page));

    ApplyRollbackAction::run($page->fresh(), $targetVersion);

    expect($translation->fresh()->title)->toBe('First version')
        ->and($cache->getFromCache($cacheKey))->toBeNull();
});
