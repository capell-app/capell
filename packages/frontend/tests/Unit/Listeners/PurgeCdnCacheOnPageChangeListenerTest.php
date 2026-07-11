<?php

declare(strict_types=1);

use Capell\Core\Events\PageUrlChanged;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Frontend\Listeners\PurgeCdnCacheOnPageChangeListener;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;

it('invalidates page render data generation when a page url changes', function (): void {
    config()->set('cache.default', 'array');

    $page = Page::factory()
        ->withTranslations()
        ->createOne();
    $translation = $page->translations->first();
    $language = Language::query()->findOrFail((int) $translation->language_id);
    $pageUrl = PageUrl::factory()
        ->page($page)
        ->site($page->site)
        ->language($language)
        ->state(['url' => '/before'])
        ->createOne();
    $cache = resolve(PublicPageRenderDataCache::class);
    $before = publicPageRenderGeneration($cache, Page::class, $page->id, $page->site_id, $language->id);

    resolve(PurgeCdnCacheOnPageChangeListener::class)->handlePageUrlChanged(new PageUrlChanged(
        page_url_id: $pageUrl->id,
        page_id: $page->id,
        site_id: $page->site_id,
        language_id: $language->id,
        old_url: '/before',
        new_url: '/after',
    ));

    expect(publicPageRenderGeneration($cache, Page::class, $page->id, $page->site_id, $language->id))->toBe($before + 1);
});

function publicPageRenderGeneration(PublicPageRenderDataCache $cache, string $pageType, int $pageId, int $siteId, int $languageId): int
{
    $reflection = new ReflectionClass($cache);
    $method = $reflection->getMethod('currentGeneration');

    return $method->invoke($cache, $pageType, $pageId, $siteId, $languageId);
}
