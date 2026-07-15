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
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Cache\PageModelCache;
use Carbon\CarbonImmutable;

it('invalidates only the persisted page dependency of localized media metadata', function (): void {
    config()->set('cache.default', 'array');
    $language = Language::factory()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $blueprint = Blueprint::factory()->page()->create();
    $dependent = Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'release-dependent')->create();
    $unrelated = Page::factory()->site($site)->type($blueprint)->published(CarbonImmutable::now())
        ->withTranslations($language, [], slug: 'release-unrelated')->create();
    $media = Media::factory()->model(Layout::factory()->create())->create();
    $translation = Translation::factory()->translatable($media)->language($language)->create(['meta' => ['alt' => 'Before']]);

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $dependent->id,
        'target_type' => Media::class,
        'target_id' => $media->id,
        'kind' => ContentGraphEdgeKind::UsesMedia,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/frontend',
        'site_id' => $dependent->site_id,
    ]);

    $cache = resolve(PageModelCache::class);
    $cache->get(Page::class, $dependent->id, $site, $language);
    $cache->get(Page::class, $unrelated->id, $site, $language);
    $dependentKey = CacheEnum::pageModel(Page::class, $dependent->id, $site->id, $language->id);
    $unrelatedKey = CacheEnum::pageModel(Page::class, $unrelated->id, $site->id, $language->id);

    $translation->update(['meta' => ['alt' => 'After']]);

    expect($cache->getFromCache($dependentKey))->toBeNull()
        ->and($cache->getFromCache($unrelatedKey))->not->toBeNull();
});
