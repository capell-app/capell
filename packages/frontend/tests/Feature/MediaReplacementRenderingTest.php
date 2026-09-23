<?php

declare(strict_types=1);

use Capell\Admin\Actions\ReplaceMediaFileAction;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\CustomPathGenerator;
use Capell\Frontend\Enums\CacheEnum;
use Capell\Frontend\Support\Cache\PageModelCache;
use Capell\Frontend\Support\State\FrontendState;
use Capell\Tests\Fixtures\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;

it('renders existing media references after replacement and invalidates the dependent page cache', function (): void {
    Storage::fake('public');
    config(['media-library.path_generator' => CustomPathGenerator::class]);
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->recycle($language)->withTranslations()->create();
    $page = Page::factory()->site($site)->published(CarbonImmutable::now())->withTranslations($language)->create();
    $media = User::factory()->createOne()->addMedia(UploadedFile::fake()->image('original.png'))->toMediaCollection();
    $property = PagePropertyValue::factory()->createOne(['site_id' => $site->id, 'page_id' => $page->id, 'media_id' => $media->getKey()]);
    Translation::factory()->language($language)->for($media, 'translatable')->createOne(['meta' => ['alt' => 'Still referenced']]);
    resolve(FrontendState::class)->withLanguage($language)->withTheme(Theme::factory()->defaultMeta()->create());
    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $page->id,
        'target_type' => Media::class,
        'target_id' => $media->getKey(),
        'kind' => ContentGraphEdgeKind::UsesMedia,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/frontend',
        'site_id' => $site->id,
    ]);
    $cache = resolve(PageModelCache::class);
    $cache->get(Page::class, $page->id, $site, $language);

    $key = CacheEnum::pageModel(Page::class, $page->id, $site->id, $language->id);
    expect($cache->getFromCache($key))->not->toBeNull();
    $url = $media->getUrl();
    $replacement = UploadedFile::fake()->image('changed-name.png', 48, 48);

    ReplaceMediaFileAction::run($media, $replacement->getPathname());

    expect($property->fresh()?->media_id)->toBe($media->getKey());
    $referenced = Media::query()->with('translations')->whereKey($property->fresh()?->media_id)->firstOrFail();
    $html = Blade::render('<x-capell::media :media="$media" />', ['media' => $referenced]);

    expect($html)->toContain($url, 'alt="Still referenced"')
        ->not->toContain('data-capell-edit', 'wire:', 'media_id', 'signed', 'capell-admin')
        ->and($cache->getFromCache($key))->toBeNull()
        ->and(Storage::disk('public')->get($referenced->getPathRelativeToRoot()))->toBe(file_get_contents($replacement->getPathname()));
});
