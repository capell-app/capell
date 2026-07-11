<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\PublicContentWidgetPayloadBuilder;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;

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
            assetManifest: new FrontendAssetManifestData([], [], [], [], $runtime),
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
            new FrontendAssetManifestData([], [], [], [], $runtime),
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
