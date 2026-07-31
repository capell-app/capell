<?php

declare(strict_types=1);

use Capell\Core\Actions\RegisterBlazeOptimizedViewsAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Actions\RenderCustomHeadAction;
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
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Livewire\Blaze\BladeService;
use Livewire\Blaze\BlazeManager;
use Livewire\Blaze\BlazeServiceProvider;
use Livewire\Blaze\Runtime\BlazeRuntime;
use Livewire\Blaze\Support\Utils as BlazeUtils;

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

    Frontend::swap(new FrontendContext(
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
    ));

    $html = Blade::render(
        '<x-capell::app.head :livewire-enabled="false" :resource-plan="$resourcePlan" />',
        ['resourcePlan' => $resourcePlan],
    );

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
    $resourcePlan = ResolveFrontendResourcePlanAction::run([
        new FrontendResourceContributionData(FrontendResourceData::style(
            'capell-app/theme:saas',
            'capell-app/theme',
            new PublicResourceSourceData('vendor/capell/themes/saas.css'),
        )),
    ]);

    Frontend::swap(new FrontendContext(
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
    ));

    $html = Blade::render(
        '<x-capell::app.head :livewire-enabled="false" :resource-plan="$resourcePlan" />',
        ['resourcePlan' => $resourcePlan],
    );

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
    $resourcePlan = ResolveFrontendResourcePlanAction::run([
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

    Frontend::swap(new FrontendContext(
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
    ));

    $html = Blade::render(
        '<x-capell::app.head :livewire-enabled="false" :resource-plan="$resourcePlan" />',
        ['resourcePlan' => $resourcePlan],
    );

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

it('renders the public head when Blaze compiles the anonymous component', function (): void {
    app()->register(BlazeServiceProvider::class);

    $componentFixturePath = __DIR__ . '/../../Fixtures/views/components';
    $customHeadComponentPath = $componentFixturePath . '/app/head/custom.blade.php';
    $tokenComponentPath = $componentFixturePath . '/app/head/tokens.blade.php';
    $headComponentPath = __DIR__ . '/../../../resources/views/components/app/head/index.blade.php';

    $blazeBladeService = resolve(BladeService::class);
    $blazeViewFactory = new ReflectionProperty(BladeService::class, 'view')->getValue($blazeBladeService);
    $bladeEngine = View::getEngineResolver()->resolve('blade');

    throw_unless($bladeEngine instanceof CompilerEngine, RuntimeException::class, 'Expected the Blade compiler engine.');

    $bladeCompiler = $bladeEngine->getCompiler();

    throw_unless($bladeCompiler instanceof BladeCompiler, RuntimeException::class, 'Expected the Blade compiler.');

    View::addNamespace('capell-frontend-test', $componentFixturePath);
    $blazeViewFactory->addNamespace('capell-frontend-test', $componentFixturePath);
    View::getFinder()->prependNamespace('capell', dirname($componentFixturePath));
    $blazeViewFactory->getFinder()->prependNamespace('capell', dirname($componentFixturePath));

    foreach ([$bladeCompiler, $blazeBladeService->compiler] as $componentCompiler) {
        $componentCompiler->component('capell-frontend-test::app.head.custom', 'capell::app.head.custom');
        $componentCompiler->component('capell-frontend-test::app.head.tokens', 'capell::app.head.tokens');
    }

    expect(RegisterBlazeOptimizedViewsAction::run($componentFixturePath))->toBeTrue()
        ->and(RegisterBlazeOptimizedViewsAction::run($headComponentPath))->toBeTrue();
    expect(realpath($blazeBladeService->componentNameToPath('capell::app.head.custom')))
        ->toBe(realpath($customHeadComponentPath));

    File::delete($bladeCompiler->getCompiledPath($customHeadComponentPath));
    File::delete($bladeCompiler->getCompiledPath($headComponentPath));
    File::delete($bladeCompiler->getCompiledPath($tokenComponentPath));

    bindAppHeadTestContext();

    $blazeRuntime = resolve(BlazeRuntime::class);
    $headComponentHash = BlazeUtils::hash($headComponentPath);
    $headFunction = '_' . $headComponentHash;
    $compiledViewPath = config('view.compiled');

    throw_unless(is_string($compiledViewPath), RuntimeException::class, 'Expected a compiled Blade view path.');

    $blazeRuntime->ensureRequired(
        $headComponentPath,
        $compiledViewPath . '/' . $headComponentHash . '.php',
    );

    throw_unless(function_exists($headFunction), RuntimeException::class, 'Expected the Blaze head function to be compiled.');

    $renderHead = Closure::fromCallable($headFunction);

    ob_start();
    $renderHead($blazeRuntime, ['livewireEnabled' => false]);
    $html = ob_get_clean();

    expect($html)
        ->toBeString()
        ->toContain('<head>')
        ->toContain('<title>')
        ->toContain('name="blaze-head-token"')
        ->toContain('content="BLAZE-HEAD"')
        ->and(resolve(BlazeManager::class)->isEnabled())->toBeTrue();
});

it('restores Blaze when custom head rendering fails', function (): void {
    app()->register(BlazeServiceProvider::class);

    $blaze = resolve(BlazeManager::class);
    $blaze->enable();

    Blade::shouldReceive('render')
        ->once()
        ->andThrow(new RuntimeException('Custom head render failed.'));

    expect(fn (): string => RenderCustomHeadAction::run('', null, null))
        ->toThrow(RuntimeException::class, 'Custom head render failed.')
        ->and($blaze->isEnabled())->toBeTrue();
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

    Frontend::swap(new FrontendContext(
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
    ));
}
