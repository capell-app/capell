<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Frontend\Support\Cache\FrontendCacheInvalidationObserver;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;

it('invalidates dependent page render data when a graph target model changes', function (): void {
    config()->set('cache.default', 'array');

    $layout = Layout::factory()->createOne();
    $page = Page::factory()
        ->withTranslations()
        ->create(['layout_id' => $layout->id]);
    $languageId = (int) $page->translations->first()->language_id;

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $page->id,
        'target_type' => Layout::class,
        'target_id' => $layout->id,
        'kind' => ContentGraphEdgeKind::UsesLayout,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
        'site_id' => $page->site_id,
    ]);

    $cache = resolve(PublicPageRenderDataCache::class);
    $before = frontendCacheObserverRenderGeneration($cache, Page::class, $page->id, $page->site_id, $languageId);

    resolve(FrontendCacheInvalidationObserver::class)->saved($layout);

    expect(frontendCacheObserverRenderGeneration($cache, Page::class, $page->id, $page->site_id, $languageId))->toBe($before + 1);
});

function frontendCacheObserverRenderGeneration(PublicPageRenderDataCache $cache, string $pageType, int $pageId, int $siteId, int $languageId): int
{
    $reflection = new ReflectionClass($cache);
    $method = $reflection->getMethod('currentGeneration');

    return $method->invoke($cache, $pageType, $pageId, $siteId, $languageId);
}
