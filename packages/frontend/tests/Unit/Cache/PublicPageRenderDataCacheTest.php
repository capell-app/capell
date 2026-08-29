<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\ThemeStudio\Preview\ThemePreviewContext;
use Capell\Frontend\Actions\BuildPublicPageRenderDataAction;
use Capell\Frontend\Contracts\PublicContentWidgetPayloadBuilder;
use Capell\Frontend\Contracts\PublicRenderDataContributor;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Data\PublicRenderDataCacheDependencyData;
use Capell\Frontend\Data\PublicRenderDataContributionData;
use Capell\Frontend\Data\PublicRenderDataContributionMetadataData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Capell\Frontend\Support\Cache\PublicRenderDataCacheDependencyRegistry;
use Capell\Frontend\Support\Render\PublicRenderDataContributorRegistry;

it('builds deterministic render data cache keys from page site language strategy and content version', function (): void {
    $page = Page::factory()
        ->withTranslations()
        ->createOne();
    $language = Language::query()->findOrFail((int) $page->translations->first()->language_id);
    $site = Site::query()->findOrFail((int) $page->site_id);
    $layout = Layout::query()->find($page->layout_id);
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);

    $key = resolve(PublicPageRenderDataCache::class)->keyForContext(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: $site->theme,
        runtimeManifest: $runtime,
    ));

    expect($key)->toContain('public-render-data-' . Page::class . '-' . $page->id)
        ->and($key)->toContain('site-' . $site->id)
        ->and($key)->toContain('lang-' . $language->id)
        ->and($key)->toContain('strategy-blade');
});

it('isolates render data cache entries for theme previews', function (): void {
    $page = Page::factory()
        ->withTranslations()
        ->createOne();
    $language = Language::query()->findOrFail((int) $page->translations->first()->language_id);
    $site = Site::query()->findOrFail((int) $page->site_id);
    $context = new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: Layout::query()->find($page->layout_id),
        theme: $site->theme,
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );
    $cache = resolve(PublicPageRenderDataCache::class);
    $defaultKey = $cache->keyForContext($context);

    app()->instance(ThemePreviewContext::class, new ThemePreviewContext(
        themeKey: 'brutalist',
        presetKey: 'brutalist',
        previewing: true,
    ));
    $brutalistKey = $cache->keyForContext($context);

    app()->instance(ThemePreviewContext::class, new ThemePreviewContext(
        themeKey: 'brutalist',
        presetKey: 'negative',
        previewing: true,
    ));
    $negativeKey = $cache->keyForContext($context);

    expect($defaultKey)->not->toBe($brutalistKey)
        ->and($brutalistKey)->not->toBe($negativeKey);
});

it('remembers public page render data when the render data cache is enabled', function (): void {
    config()->set('cache.default', 'array');
    config()->set('capell-frontend.public_render_data_cache', true);

    $page = Page::factory()
        ->withTranslations()
        ->createOne();
    $language = Language::query()->findOrFail((int) $page->translations->first()->language_id);
    $site = Site::query()->findOrFail((int) $page->site_id);
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $context = new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: Layout::query()->find($page->layout_id),
        theme: $site->theme,
        runtimeManifest: $runtime,
    );
    $calls = 0;

    $builder = function () use (&$calls, $context, $runtime): PublicPageRenderData {
        $calls++;

        return new PublicPageRenderData(
            page: $context->page,
            site: $context->site,
            language: $context->language,
            layout: $context->layout,
            theme: $context->theme,
            layoutGraph: null,
            runtimeManifest: $runtime,
            resourcePlan: new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty')),
            surrogateKeys: [],
        );
    };

    $cache = resolve(PublicPageRenderDataCache::class);

    $first = $cache->remember($context, $builder);
    $second = $cache->remember($context, $builder);

    expect($first)->toBeInstanceOf(PublicPageRenderData::class)
        ->and($second)->toBeInstanceOf(PublicPageRenderData::class)
        ->and($calls)->toBe(1);
});

it('does not rebuild contributor values on a warm cache hit', function (): void {
    config()->set('cache.default', 'array');
    config()->set('capell-frontend.public_render_data_cache', true);

    $page = Page::factory()->withTranslations()->createOne();
    $language = Language::query()->findOrFail((int) $page->translations->first()->language_id);
    $site = Site::query()->findOrFail((int) $page->site_id);
    $context = new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: Layout::query()->find($page->layout_id),
        theme: $site->theme,
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    );
    $contributeCalls = 0;

    resolve(PublicRenderDataContributorRegistry::class)->register(new class($contributeCalls) implements PublicRenderDataContributor
    {
        public function __construct(private int &$contributeCalls) {}

        public function key(): string
        {
            return 'warm-hit';
        }

        public function supports(FrontendRenderContextData $context): bool
        {
            return true;
        }

        public function metadata(FrontendRenderContextData $context): PublicRenderDataContributionMetadataData
        {
            return new PublicRenderDataContributionMetadataData('warm-hit-v1');
        }

        public function contribute(FrontendRenderContextData $context): PublicRenderDataContributionData
        {
            $this->contributeCalls++;

            return new PublicRenderDataContributionData((object) ['value' => 'built']);
        }

        public function cacheDependencyModelTypes(): array
        {
            return [];
        }
    });

    $cache = resolve(PublicPageRenderDataCache::class);
    $builder = static fn (): PublicPageRenderData => BuildPublicPageRenderDataAction::run($context);

    $cache->remember($context, $builder);
    $cache->remember($context, $builder);

    expect($contributeCalls)->toBe(1);
});

it('retains both destinations when the same model is registered repeatedly', function (): void {
    config()->set('cache.default', 'array');
    $model = Page::factory()->createOne();
    $dependency = PublicRenderDataCacheDependencyData::forModel($model);
    $registry = resolve(PublicRenderDataCacheDependencyRegistry::class);

    $registry->register($dependency, 'render-page-a');
    $registry->register($dependency, 'render-page-b');

    expect(collect($registry->rulesFor($model))->pluck('cacheKey')->all())
        ->toBe(['render-page-a', 'render-page-b']);
});

it('isolates dependency indexes by the Capell cache host namespace', function (): void {
    config()->set('cache.default', 'array');
    $model = Page::factory()->createOne();
    $dependency = PublicRenderDataCacheDependencyData::forModel($model);
    $registry = resolve(PublicRenderDataCacheDependencyRegistry::class);

    config()->set('app.url', 'https://tenant-a.example.test');
    $registry->register($dependency, 'tenant-a-render');

    config()->set('app.url', 'https://tenant-b.example.test');
    expect($registry->rulesFor($model))->toBe([]);

    config()->set('app.url', 'https://tenant-a.example.test');
    expect(collect($registry->rulesFor($model))->pluck('cacheKey')->all())->toBe(['tenant-a-render']);
});

it('changes cache keys and entries when the optional payload schema fingerprint changes', function (): void {
    config()->set('cache.default', 'array');
    config()->set('capell-frontend.public_render_data_cache', true);

    $fingerprintedBuilder = new class implements PublicContentWidgetPayloadBuilder
    {
        public string $version = 'schema-v1';

        public function fingerprint(): string
        {
            return $this->version;
        }

        public function build(FrontendRenderContextData $context): array
        {
            return [];
        }
    };
    app()->instance(PublicContentWidgetPayloadBuilder::class, $fingerprintedBuilder);

    $page = Page::factory()->withTranslations()->createOne();
    $language = Language::query()->findOrFail((int) $page->translations->first()->language_id);
    $site = Site::query()->findOrFail((int) $page->site_id);
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $context = new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: Layout::query()->find($page->layout_id),
        theme: $site->theme,
        runtimeManifest: $runtime,
    );
    $cache = resolve(PublicPageRenderDataCache::class);
    $firstKey = $cache->keyForContext($context);
    $calls = 0;
    $factory = function () use (&$calls, $context, $runtime): PublicPageRenderData {
        $calls++;

        return new PublicPageRenderData(
            $context->page,
            $context->site,
            $context->language,
            $context->layout,
            $context->theme,
            null,
            $runtime,
            new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty')),
            [],
        );
    };

    $cache->remember($context, $factory);
    $cache->remember($context, $factory);

    $fingerprintedBuilder->version = 'schema-v2';
    $secondKey = $cache->keyForContext($context);
    $cache->remember($context, $factory);

    expect($firstKey)->not->toBe($secondKey)
        ->and($calls)->toBe(2);
});

it('bumps the render data cache key generation when a page render entry is invalidated', function (): void {
    config()->set('cache.default', 'array');

    $page = Page::factory()
        ->withTranslations()
        ->createOne();
    $language = Language::query()->findOrFail((int) $page->translations->first()->language_id);
    $site = Site::query()->findOrFail((int) $page->site_id);
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $context = new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: Layout::query()->find($page->layout_id),
        theme: $site->theme,
        runtimeManifest: $runtime,
    );
    $cache = resolve(PublicPageRenderDataCache::class);

    $firstKey = $cache->keyForContext($context);

    $cache->invalidate(Page::class, $page->id, $site->id, $language->id);

    $secondKey = $cache->keyForContext($context);

    expect($secondKey)->not->toBe($firstKey)
        ->and(publicRenderDataGenerationFromKey($secondKey))->toBe(publicRenderDataGenerationFromKey($firstKey) + 1);
});

function publicRenderDataGenerationFromKey(string $key): int
{
    preg_match('/-gen-(\d+)$/', $key, $matches);

    return (int) ($matches[1] ?? 0);
}
