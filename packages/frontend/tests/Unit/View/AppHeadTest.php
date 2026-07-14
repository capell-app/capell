<?php

declare(strict_types=1);

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\ResolveFrontendResourcePlanAction;
use Capell\Frontend\Contracts\FrontendResourcePlanRenderer;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendMediaHintData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Facades\Frontend;
use Capell\Frontend\Support\CapellFrontendContext;
use Illuminate\Support\Facades\Blade;

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
    $resourcePlan = new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty'));

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'resourcePlan' => $resourcePlan,
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
    $resourcePlan = resolve(ResolveFrontendResourcePlanAction::class)->handle([
        new FrontendResourceContributionData(FrontendResourceData::style(
            'capell-app/theme:saas',
            'capell-app/theme',
            new PublicResourceSourceData('vendor/capell/themes/saas.css'),
        )),
    ]);

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'resourcePlan' => $resourcePlan,
            'runtimeManifest' => $runtimeManifest,
        ],
        slug: null,
    )));
    Frontend::clearResolvedInstance(CapellFrontendContext::class);

    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');

    expect($html)->toContain('href="http://localhost/vendor/capell/themes/saas.css"')
        ->and($html)->not->toContain('@vite');
});

it('delegates public resource rendering through the resource plan renderer contract', function (): void {
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
    $resourcePlan = resolve(ResolveFrontendResourcePlanAction::class)->handle([
        new FrontendResourceContributionData(FrontendResourceData::style(
            'capell-app/theme:saas',
            'capell-app/theme',
            new PublicResourceSourceData('vendor/capell/themes/saas.css'),
        )),
    ]);

    app()->instance(FrontendResourcePlanRenderer::class, new class implements FrontendResourcePlanRenderer
    {
        public function render(FrontendResourcePlanData $plan, FrontendResourceContextData $context): RenderedFrontendResourcesData
        {
            expect($plan->headResources)->toHaveCount(1)
                ->and($context->layout)->not()->toBeNull()
                ->and($context->theme)->not()->toBeNull();

            return new RenderedFrontendResourcesData('<meta name="asset-renderer-contract" content="used">', '', []);
        }
    });

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'resourcePlan' => $resourcePlan,
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

it('renders responsive lcp preload attributes', function (): void {
    bindAppHeadTestContext([
        'mediaHints' => [
            new FrontendMediaHintData(
                url: 'https://example.test/hero-large.webp',
                imageSrcset: implode(', ', [
                    'https://example.test/hero-small.webp 640w',
                    'https://example.test/hero-large.webp 2560w',
                ]),
                imageSizes: '100vw',
            ),
        ],
    ]);

    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');

    expect($html)
        ->toContain('imagesrcset="https://example.test/hero-small.webp 640w, https://example.test/hero-large.webp 2560w"')
        ->toContain('imagesizes="100vw"');
});

it('uses swap rendering for local theme fonts', function (): void {
    $theme = Theme::factory()->defaultMeta()->create();
    $theme->setAttribute('meta', [
        ...$theme->meta,
        'fonts' => [[
            'type' => 'local',
            'name' => 'Inter',
            'files' => ['fonts/inter.woff2'],
            'style' => 'normal',
            'weight' => '400',
        ]],
    ]);

    bindAppHeadTestContext(theme: $theme);

    $html = Blade::render('<x-capell::app.head :livewire-enabled="false" />');

    expect($html)
        ->toContain("font-family: 'Inter'")
        ->toContain('font-display: swap');
});

/** @param array<string, mixed> $params */
function bindAppHeadTestContext(array $params = [], ?Theme $theme = null): void
{
    $language = Language::factory()->createOne();
    $theme ??= Theme::factory()->defaultMeta()->create();
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
    $resourcePlan = new FrontendResourcePlanData([], [], [], [], [], [], [], hash('sha256', 'empty'));

    app()->instance(CapellFrontendContext::class, new CapellFrontendContext(new FrontendContext(
        site: $site,
        language: $language,
        page: $page,
        layout: $layout,
        theme: $theme,
        params: [
            'resourcePlan' => $resourcePlan,
            'runtimeManifest' => $runtimeManifest,
            ...$params,
        ],
        slug: null,
    )));
    Frontend::clearResolvedInstance(CapellFrontendContext::class);
}
