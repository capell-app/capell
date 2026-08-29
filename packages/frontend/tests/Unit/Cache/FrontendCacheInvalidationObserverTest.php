<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionMetadataData;
use Capell\Frontend\Support\Bootstrap\FrontendEventBootstrapper;
use Capell\Frontend\Support\Cache\CacheInvalidationExecutor;
use Capell\Frontend\Support\Cache\FrontendCacheInvalidationObserver;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Capell\Frontend\Support\Cache\PublicRenderDataCacheDependencyRegistry;
use Capell\Frontend\Support\Render\PublicRenderDataContributorRegistry;
use Illuminate\Database\Eloquent\Model;

class FixtureCatalogueProduct extends Model
{
    public $timestamps = false;

    protected $table = 'pages';

    protected $guarded = [];
}

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

it('observes declared catalogue models and preserves unrelated render entries', function (): void {
    config()->set('cache.default', 'array');
    $page = Page::factory()->withTranslations()->createOne();
    $product = new FixtureCatalogueProduct;
    $product->setRawAttributes(['id' => $page->id, 'name' => $page->name]);
    $product->exists = true;

    resolve(PublicRenderDataContributorRegistry::class)->register(new class implements PublicRenderDataContributor
    {
        public function key(): string
        {
            return 'fixture.catalogue';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            return true;
        }

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            return new PublicRenderDataContributionMetadataData('fixture-v1', cacheDependencies: [PublicRenderDataCacheDependencyData::forModel(new FixtureCatalogueProduct(['id' => 1]))]);
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            return new PublicRenderDataContributionData((object) ['name' => 'fixture']);
        }

        public function cacheDependencyModelTypes(): array
        {
            return [FixtureCatalogueProduct::class];
        }
    });
    resolve(FrontendEventBootstrapper::class)->boot();

    $dependency = PublicRenderDataCacheDependencyData::forModel($product);
    $dependencyRegistry = resolve(PublicRenderDataCacheDependencyRegistry::class);
    $executor = resolve(CacheInvalidationExecutor::class);
    $dependencyRegistry->register($dependency, 'catalogue-render');
    $executor->setToCache('catalogue-render', 'stale');
    $executor->setToCache('unrelated-render', 'keep');

    $product->name = 'updated';
    $product->save();

    expect($executor->getFromCache('catalogue-render'))->toBeNull()
        ->and($executor->getFromCache('unrelated-render'))->toBe('keep');
});

function frontendCacheObserverRenderGeneration(PublicPageRenderDataCache $cache, string $pageType, int $pageId, int $siteId, int $languageId): int
{
    $reflection = new ReflectionClass($cache);
    $method = $reflection->getMethod('currentGeneration');

    return $method->invoke($cache, $pageType, $pageId, $siteId, $languageId);
}
