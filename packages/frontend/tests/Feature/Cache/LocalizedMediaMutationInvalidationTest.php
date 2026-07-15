<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\Cache\PublicPageRenderDataCache;
use Carbon\CarbonImmutable;

it('invalidates only pages that depend on mutated localized media metadata', function (string $field, mixed $value): void {
    config()->set('cache.default', 'array');
    config()->set('capell-frontend.public_render_data_cache', true);

    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $site->load('siteDomains');

    $blueprint = Blueprint::factory()->page()->create();
    $dependentPage = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'localized-media-dependent')
        ->create();
    $unrelatedPage = Page::factory()
        ->site($site)
        ->type($blueprint)
        ->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'localized-media-unrelated')
        ->create();
    $media = Media::factory()->model(Layout::factory()->create())->create();
    $translation = Translation::factory()
        ->translatable($media)
        ->language($language)
        ->create(['meta' => ['alt' => 'Before']]);

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $dependentPage->id,
        'target_type' => Media::class,
        'target_id' => $media->id,
        'kind' => ContentGraphEdgeKind::UsesMedia,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/frontend',
        'site_id' => $site->id,
    ]);

    $pageModelCache = resolve(PageModelCache::class);
    $pageModelCache->get(Page::class, $dependentPage->id, $site, $language);
    $pageModelCache->get(Page::class, $unrelatedPage->id, $site, $language);

    $renderCache = resolve(PublicPageRenderDataCache::class);
    $dependentRenderKey = warmLocalizedMediaRenderCache($renderCache, $dependentPage, $site, $language);
    $unrelatedRenderKey = warmLocalizedMediaRenderCache($renderCache, $unrelatedPage, $site, $language);

    /** @var Translation $coldTranslation */
    $coldTranslation = Translation::query()->findOrFail($translation->id);
    expect($coldTranslation->relationLoaded('translatable'))->toBeFalse();

    $coldTranslation->update([
        'meta' => [
            ...($coldTranslation->meta ?? []),
            $field => $value,
        ],
    ]);

    $dependentModelKey = CacheEnum::pageModel(Page::class, $dependentPage->id, $site->id, $language->id);
    $unrelatedModelKey = CacheEnum::pageModel(Page::class, $unrelatedPage->id, $site->id, $language->id);

    expect($pageModelCache->getFromCache($dependentModelKey))->toBeNull()
        ->and($pageModelCache->getFromCache($unrelatedModelKey))->not->toBeNull()
        ->and($renderCache->getFromCache($dependentRenderKey))->toBeNull()
        ->and($renderCache->getFromCache($unrelatedRenderKey))->toBeInstanceOf(PublicPageRenderData::class)
        ->and($coldTranslation->relationLoaded('translatable'))->toBeTrue();
})->with([
    'alternative text' => ['alt', 'After'],
    'caption' => ['caption', 'A localized caption'],
    'credit' => ['credit', 'Capell Studio'],
    'decorative intent' => ['decorative', true],
]);

function warmLocalizedMediaRenderCache(
    PublicPageRenderDataCache $cache,
    Page $page,
    Site $site,
    Language $language,
): string {
    $runtime = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $context = new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: Layout::query()->find($page->layout_id),
        theme: $site->theme,
        runtimeManifest: $runtime,
    );
    $key = $cache->keyForContext($context);

    expect($key)->toBeString();

    $cache->remember($context, fn (): PublicPageRenderData => new PublicPageRenderData(
        page: $page,
        site: $site,
        language: $language,
        layout: $context->layout,
        theme: $context->theme,
        layoutGraph: null,
        runtimeManifest: $runtime,
        resourcePlan: new FrontendResourcePlanData([], [], [], [], [], [], [], 'test'),
        surrogateKeys: [],
    ));

    return (string) $key;
}
