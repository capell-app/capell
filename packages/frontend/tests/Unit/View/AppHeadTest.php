<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendAssetManifestRenderer;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\CapellFrontendContext;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

it('ignores non string translation meta values in the public head', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->withTranslations($language, ['title' => 'Example Site'])
        ->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Example Page'])
        ->create();

    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);
    $page->translation->meta = [
        'description' => ['nested' => true],
        'keywords' => (object) ['nested' => true],
        'title' => ['nested' => true],
    ];
    $page->translation->meta_title = ['nested' => true];
    $page->translation->meta_description = ['nested' => true];
    $page->translation->meta_keywords = (object) ['nested' => true];

    $runtimeManifest = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $assetManifest = new FrontendAssetManifestData(
        css: [],
        js: [],
        inline: [],
        preloads: [],
        runtime: $runtimeManifest,
    );

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'assetManifest' => $assetManifest,
            'runtimeManifest' => $runtimeManifest,
        ],
        slug: null,
    )));
    Frontend::clearResolvedInstance(CapellFrontendContext::class);

    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');

    expect($html)->toContain('<title>')
        ->and($html)->toContain('Example Page')
        ->and($html)->toContain('Example Site')
        ->and($html)->not->toContain('name="description"')
        ->and($html)->not->toContain('name="keywords"')
        ->and($html)->not->toContain('Array');
});

it('renders static theme css assets without requiring a vite manifest entry', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->withTranslations($language, ['title' => 'Example Site'])
        ->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Example Page'])
        ->create();

    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);

    $runtimeManifest = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $assetManifest = new FrontendAssetManifestData(
        css: [
            new FrontendAssetRequirementData(
                handle: 'theme-meta:saas',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'vendor/capell/themes/saas.css',
            ),
        ],
        js: [],
        inline: [],
        preloads: [],
        runtime: $runtimeManifest,
    );

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'assetManifest' => $assetManifest,
            'runtimeManifest' => $runtimeManifest,
        ],
        slug: null,
    )));
    Frontend::clearResolvedInstance(CapellFrontendContext::class);

    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');

    expect($html)->toContain('href="http://localhost/vendor/capell/themes/saas.css"')
        ->and($html)->not->toContain('@vite');
});

it('delegates public manifest rendering through the asset manifest renderer contract', function (): void {
    $language = Language::factory()->createOne();
    $theme = Theme::factory()->defaultMeta()->create();
    $site = Site::factory()
        ->language($language)
        ->theme($theme)
        ->withTranslations($language, ['title' => 'Example Site'])
        ->create();
    $layout = Layout::factory()->site($site)->create();
    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, ['title' => 'Example Page'])
        ->create();

    $site->load(['language', 'siteDomain', 'siteDomains', 'translation']);
    $page->load(['pageUrl', 'pageUrls.language', 'pageUrls.siteDomain', 'translation']);

    $runtimeManifest = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $assetManifest = new FrontendAssetManifestData(
        css: [
            new FrontendAssetRequirementData(
                handle: 'theme-meta:saas',
                kind: FrontendAssetRequirementData::KIND_CSS,
                source: 'vendor/capell/themes/saas.css',
            ),
        ],
        js: [],
        inline: [],
        preloads: [],
        runtime: $runtimeManifest,
    );

    app()->instance(FrontendAssetManifestRenderer::class, new class implements FrontendAssetManifestRenderer
    {
        public function render(FrontendAssetManifestData $manifest, ?FrontendAssetContextData $context = null): HtmlString
        {
            expect($manifest->css)->toHaveCount(1)
                ->and($context?->layout)->not()->toBeNull()
                ->and($context?->theme)->not()->toBeNull();

            return new HtmlString('<meta name="asset-renderer-contract" content="used">');
        }
    });

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'assetManifest' => $assetManifest,
            'runtimeManifest' => $runtimeManifest,
        ],
        slug: null,
    )));
    Frontend::clearResolvedInstance(CapellFrontendContext::class);

    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');

    expect($html)
        ->toContain('<meta name="asset-renderer-contract" content="used">')
        ->not->toContain('vendor/capell/themes/saas.css');
});
