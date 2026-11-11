<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentStructure;
use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Actions\BuildSelectedFrontendWidgetResourceUsagesAction;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;

it('extracts selected resource groups and loading overrides from page widget metadata', function (): void {
    $language = Language::factory()->createOne(['code' => 'en']);
    $site = Site::factory()->createOne(['language_id' => $language->id]);
    $layout = Layout::factory()->site($site)->createOne();
    $blueprint = Blueprint::factory()
        ->page()
        ->contentStructure(ContentStructure::Blocks)
        ->createOne();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->type($blueprint)
        ->withTranslations($language, [
            'title' => 'Gallery page',
        ], slug: '/')
        ->createOne();

    $page->translation->update([
        'content' => [
            [
                'type' => 'gallery',
                'data' => [
                    '__capell' => [
                        'resources' => [
                            'groups' => ['package.gallery'],
                            'loading_overrides' => [
                                [
                                    'group' => 'package.gallery',
                                    'loading_strategy' => PresentationLoadingStrategy::Idle->value,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $page->unsetRelation('translation');

    $usages = BuildSelectedFrontendWidgetResourceUsagesAction::run(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: Theme::factory()->createOne(),
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($usages)->toHaveCount(1)
        ->and($usages[0]['widgetKey'])->toBe('gallery')
        ->and($usages[0]['resourceGroup'])->toBe('package.gallery')
        ->and($usages[0]['publicId'])->toStartWith('resource-')
        ->and($usages[0]['loadingStrategy'])->toBe(PresentationLoadingStrategy::Idle->value);
});

it('extracts selected resource groups from layout container widget metadata', function (): void {
    $language = Language::factory()->createOne(['code' => 'en']);
    $site = Site::factory()->createOne(['language_id' => $language->id]);
    $layout = Layout::factory()->site($site)->createOne([
        'containers' => [
            'main' => [
                'widgets' => [
                    [
                        'widget_key' => 'map',
                        'meta' => [
                            'resources' => [
                                'groups' => ['package.map'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Map page'], slug: '/')
        ->createOne();

    $usages = BuildSelectedFrontendWidgetResourceUsagesAction::run(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: Theme::factory()->createOne(),
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect($usages)->toHaveCount(1)
        ->and($usages[0]['widgetKey'])->toBe('map')
        ->and($usages[0]['resourceGroup'])->toBe('package.map');
});

it('reads page widget metadata from the translation in the render language', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->createOne(['language_id' => $english->id]);
    $layout = Layout::factory()->site($site)->createOne();
    $blueprint = Blueprint::factory()->page()->contentStructure(ContentStructure::Blocks)->createOne();
    $page = Page::factory()->site($site)->layout($layout)->type($blueprint)->createOne();
    $page->translations()->forceDelete();
    // The French row is written first, so an unconstrained read returns it.
    Translation::factory()->translatable($page)->language($french)->createOne(['content' => []]);
    Translation::factory()->translatable($page)->language($english)->createOne(['content' => [
        ['type' => 'gallery', 'data' => ['__capell' => ['resources' => ['groups' => ['package.gallery']]]]],
    ]]);
    $page->unsetRelation('translation');

    $usages = BuildSelectedFrontendWidgetResourceUsagesAction::run(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $english,
        layout: $layout,
        theme: Theme::factory()->createOne(),
        runtimeManifest: FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly),
    ));

    expect(collect($usages)->pluck('resourceGroup')->all())->toContain('package.gallery');
});
