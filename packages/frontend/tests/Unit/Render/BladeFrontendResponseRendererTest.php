<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\FrontendRenderPayload;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Frontend\Data\MainContentRenderHookData;
use Capell\Frontend\Data\PublicPageRenderData;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Render\BladeFrontendResponseRenderer;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

it('declares the blade runtime', function (): void {
    $renderer = new BladeFrontendResponseRenderer;

    expect($renderer->runtime())->toBe(FrontendRuntime::Blade);
});

it('registers the blade renderer in the frontend renderer registry', function (): void {
    $renderer = resolve(FrontendResponseRendererRegistry::class)
        ->forRuntime(FrontendRuntime::Blade);

    expect($renderer)->toBeInstanceOf(BladeFrontendResponseRenderer::class);
});

it('uses the capell layout path for pages with layout builder containers', function (): void {
    $site = Site::factory()->make(['name' => 'Fresh Site']);
    $site->setRelation('blueprint', Blueprint::factory()->make());

    $language = Language::factory()->make(['code' => 'en']);
    $theme = Theme::factory()->make(['key' => 'default', 'meta' => []]);
    $translation = Translation::factory()->make([
        'title' => 'Container page',
        'content' => '<p>Theme Studio fallback should not render.</p>',
        'meta' => [],
    ]);
    $page = Page::factory()->make(['name' => 'Container page', 'meta' => []]);
    $page->setRelation('translation', $translation);
    $page->setRelation('blueprint', Blueprint::factory()->make());

    $mainContentData = null;

    resolve(RenderHookRegistry::class)->registerCallable(
        RenderHookLocation::MainContent,
        function (RenderHookContext $context) use (&$mainContentData): string {
            expect($context->item)->toBeInstanceOf(MainContentRenderHookData::class);

            throw_unless($context->item instanceof MainContentRenderHookData, RuntimeException::class, 'Expected the main content hook data.');

            $mainContentData = $context->item;

            return '<section>Layout builder hook output</section>';
        },
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );
    resolve(RenderHookRegistry::class)->registerCallable(
        RenderHookLocation::AfterContent,
        function (RenderHookContext $context) use (&$mainContentData): string {
            expect($context->location)->toBe(RenderHookLocation::AfterContent->value)
                ->and($context->item)->toBe($mainContentData);

            return '<aside data-after-content-hook>After content hook output</aside>';
        },
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );
    resolve(RenderHookRegistry::class)->registerCallable(
        RenderHookLocation::AfterContent,
        static fn (): string => '<aside>wrong-scope-after-content</aside>',
        scenario: 'another-frontend-scenario',
        target: 'capell::layout.main',
    );

    $layout = Layout::factory()->make(['key' => 'home']);
    $layout->admin = [
        'system_page_layout' => false,
    ];
    $layout->meta = [
        'layout_file' => 'capell-renderer-test::layout',
    ];
    $layout->containers = [
        'main' => [
            'widgets' => [
                ['widget_key' => 'hero'],
            ],
        ],
    ];

    bindBladeRendererContext($page, $site, $language, $layout, $theme);

    $viewPath = sys_get_temp_dir() . '/capell-renderer-test-' . bin2hex(random_bytes(6));
    View::addNamespace('capell-renderer-test', $viewPath);
    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/layout.blade.php', '<html><body>{!! $slot !!}</body></html>');

    try {
        $renderContext = new FrontendRenderContextData(
            page: $page,
            site: $site,
            language: $language,
            layout: $layout,
            theme: $theme,
        );
        $response = (new BladeFrontendResponseRenderer)->render($renderContext);

        throw_unless($response instanceof Response, RuntimeException::class, 'Expected blade renderer to return an HTTP response.');

        $content = (string) $response->getContent();
        $mainPosition = strpos($content, 'Layout builder hook output');
        $afterPosition = strpos($content, 'After content hook output');

        throw_unless(
            is_int($mainPosition) && is_int($afterPosition),
            RuntimeException::class,
            'Expected both main and after-content hooks to render.',
        );

        expect($afterPosition)->toBeGreaterThan($mainPosition)
            ->and($content)
            ->not->toContain('wrong-scope-after-content')
            ->not->toContain('default-theme-shell')
            ->not->toContain('Theme Studio fallback should not render');

        resolve(RenderHookRegistry::class)->registerCallable(
            RenderHookLocation::AfterContent,
            static fn (): string => '<div data-model-id="42">Unsafe hook output</div>',
            scenario: 'frontend-main-layout',
            target: 'capell::layout.main',
        );

        expect(fn (): Symfony\Component\HttpFoundation\Response|Responsable => (new BladeFrontendResponseRenderer)->render($renderContext))
            ->toThrow(RuntimeException::class, 'Public HTML contains an authoring marker.');
    } finally {
        File::deleteDirectory($viewPath);
    }
});

it('renders custom master and layout files from layout metadata', function (): void {
    $viewNamespace = 'capell-renderer-test-' . str_replace('.', '', uniqid('', true));
    $viewPath = storage_path('framework/testing/' . $viewNamespace);
    $site = Site::factory()->make(['name' => 'Custom layout site']);
    $site->setRelation('blueprint', Blueprint::factory()->make());

    $language = Language::factory()->make(['code' => 'en']);
    $theme = Theme::factory()->make(['key' => 'missing-theme', 'meta' => []]);
    $translation = Translation::factory()->make(['title' => 'Custom page title']);
    $page = Page::factory()->make(['meta' => []]);
    $page->setRelation('translation', $translation);

    $layout = Layout::factory()->make([
        'key' => 'custom-layout',
        'meta' => [
            'master_file' => $viewNamespace . '::master',
            'layout_file' => $viewNamespace . '::layout',
        ],
    ]);

    bindBladeRendererContext($page, $site, $language, $layout, $theme);

    View::addNamespace($viewNamespace, $viewPath);
    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/master.blade.php', '<main data-custom-master>{{ $componentName }}</main>');
    File::put($viewPath . '/layout.blade.php', '<html><body data-custom-shell>{{ $pageRecord->translation->title }}{!! $slot !!}</body></html>');

    try {
        $response = (new BladeFrontendResponseRenderer)->render(new FrontendRenderContextData(
            page: $page,
            site: $site,
            language: $language,
            layout: $layout,
            theme: $theme,
        ));
    } finally {
        File::deleteDirectory($viewPath);
    }

    throw_unless($response instanceof Response, RuntimeException::class, 'Expected blade renderer to return an HTTP response.');

    expect($response->getContent())
        ->toContain('data-custom-master')
        ->toContain('Custom page title')
        ->toContain('data-custom-shell')
        ->not->toContain('data-model-id')
        ->not->toContain('data-field-path')
        ->not->toContain('signature=');
});

it('honours authored masters for prepared graphs while retaining graph and container fallbacks', function (string $graphState, string $masterState, string $expectedContent, int $expectedRawHookCalls): void {
    $viewNamespace = 'capell-renderer-prepared-' . bin2hex(random_bytes(6));
    $viewPath = storage_path('framework/testing/' . $viewNamespace);
    $site = Site::factory()->make(['name' => 'Prepared graph site']);
    $site->setRelation('blueprint', Blueprint::factory()->make());

    $language = Language::factory()->make(['code' => 'en']);
    $theme = Theme::factory()->make(['key' => 'default', 'meta' => []]);
    $page = Page::factory()->make(['name' => 'Prepared graph page', 'meta' => []]);
    $page->setRelation('translation', Translation::factory()->make(['title' => 'Prepared graph title', 'meta' => []]));
    $page->setRelation('blueprint', Blueprint::factory()->make());

    $layout = Layout::factory()->make([
        'key' => 'home',
        'meta' => [
            'layout_file' => $viewNamespace . '::layout',
            ...match ($masterState) {
                'explicit' => ['master_file' => $viewNamespace . '::master'],
                'blank' => ['master_file' => ''],
                default => [],
            },
        ],
    ]);
    $layout->admin = ['system_page_layout' => false];
    $layout->containers = ['main' => ['widgets' => [['widget_key' => 'page-content']]]];

    $rawHookCalls = 0;

    resolve(RenderHookRegistry::class)->registerCallable(
        RenderHookLocation::MainContent,
        static function () use (&$rawHookCalls): string {
            $rawHookCalls++;

            return '<section>Raw container fallback</section>';
        },
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );
    bindBladeRendererContext($page, $site, $language, $layout, $theme);

    $runtimeManifest = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $layoutGraph = $graphState === 'missing' ? null : (object) [
        'containers' => [
            'main' => [
                'widgets' => [
                    [
                        'data' => [
                            'sections' => $graphState === 'populated'
                                ? [['html' => '<section>Hydrated graph content</section>', 'order' => 1]]
                                : [['html' => ''], ['html' => '  '], ['html' => null]],
                        ],
                    ],
                ],
            ],
        ],
    ];
    $publicRenderData = new PublicPageRenderData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: $theme,
        layoutGraph: $layoutGraph,
        runtimeManifest: $runtimeManifest,
        resourcePlan: new FrontendResourcePlanData(
            headResources: [],
            bodyEndResources: [],
            lazyActivationGraphs: [],
            hints: [],
            aliases: [],
            diagnostics: [],
            cspOrigins: [],
            fingerprint: 'prepared-renderer-contract',
        ),
        surrogateKeys: [],
    );
    View::addNamespace($viewNamespace, $viewPath);
    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/master.blade.php', '<main>Authored master content</main>');
    File::put($viewPath . '/layout.blade.php', '<html><body>{!! $slot !!}</body></html>');

    try {
        $response = (new BladeFrontendResponseRenderer)->render(new FrontendRenderContextData(
            page: $page,
            site: $site,
            language: $language,
            layout: $layout,
            theme: $theme,
            runtimeManifest: $runtimeManifest,
            publicRenderData: $publicRenderData,
        ));
        throw_unless($response instanceof Response, RuntimeException::class, 'Expected blade renderer to return an HTTP response.');

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getContent())->toContain($expectedContent)
            ->not->toContain('data-model-id')
            ->not->toContain('data-field-path')
            ->not->toContain('signature=')
            ->and($rawHookCalls)->toBe($expectedRawHookCalls);

        foreach (['Authored master content', 'Hydrated graph content', 'Raw container fallback'] as $otherContent) {
            if ($otherContent !== $expectedContent) {
                expect($response->getContent())->not->toContain($otherContent);
            }
        }
    } finally {
        File::deleteDirectory($viewPath);
    }
})->with([
    'empty graph and explicit master' => ['empty', 'explicit', 'Authored master content', 0],
    'graph-less containers and explicit master' => ['missing', 'explicit', 'Raw container fallback', 1],
    'empty graph without a master' => ['empty', 'missing', 'Raw container fallback', 1],
    'empty graph with a blank master' => ['empty', 'blank', 'Raw container fallback', 1],
    'populated graph and explicit master' => ['populated', 'explicit', 'Authored master content', 0],
    'populated graph without a master' => ['populated', 'missing', 'Hydrated graph content', 0],
]);

it('renders every widget and container presentation unless the entire graph can supply the slot', function (string $hydration, string $presentation, bool $separateContainers): void {
    $viewNamespace = 'capell-renderer-hydration-' . bin2hex(random_bytes(6));
    $viewPath = sys_get_temp_dir() . '/' . $viewNamespace;
    $site = Site::factory()->make(['name' => 'Hydration site']);
    $site->setRelation('blueprint', Blueprint::factory()->make());

    $language = Language::factory()->make(['code' => 'en']);
    $theme = Theme::factory()->make(['key' => 'default', 'meta' => []]);
    $page = Page::factory()->make(['name' => 'Hydration page', 'meta' => []]);
    $page->setRelation('translation', Translation::factory()->make(['meta' => []]));
    $page->setRelation('blueprint', Blueprint::factory()->make());

    $layout = Layout::factory()->make([
        'key' => 'home',
        'admin' => ['system_page_layout' => false],
        'meta' => ['layout_file' => $viewNamespace . '::layout'],
    ]);
    $containerMeta = match ($presentation) {
        'none' => [],
        'landmark' => ['landmark' => 'main'],
        default => ['html_class' => 'ppc-shell'],
    };
    $widgets = [
        ['widget_key' => 'page-content', 'html' => '<section>First widget</section>'],
        ['widget_key' => 'sidebar', 'html' => '<aside>Second widget</aside>'],
    ];
    $layout->containers = $separateContainers
        ? ['main' => ['meta' => $containerMeta, 'widgets' => [$widgets[0]]], 'sidebar' => ['widgets' => [$widgets[1]]]]
        : ['main' => ['meta' => $containerMeta, 'widgets' => $widgets]];

    $secondSections = match ($hydration) {
        'full' => [['html' => '<aside>Second widget</aside>', 'order' => 0]],
        'blank' => [['html' => '  '], ['html' => null], ['html' => 42]],
        default => [],
    };
    $graphWidgets = [
        (object) ['data' => ['sections' => [
            ['html' => '<section>First widget</section>', 'order' => 2],
            ['html' => '<p>First introduction</p>', 'order' => 1],
            ['html' => '  '],
        ]]],
        (object) ['data' => ['sections' => $secondSections]],
    ];
    $graphMeta = in_array($presentation, ['graph', 'landmark'], true) ? $containerMeta : [];
    $graphContainers = $separateContainers
        ? [(object) ['meta' => $graphMeta, 'widgets' => [$graphWidgets[0]]], (object) ['meta' => [], 'widgets' => [$graphWidgets[1]]]]
        : [(object) ['meta' => $graphMeta, 'widgets' => $graphWidgets]];
    // Real graph producers omit container metadata; presentation remains on Layout.
    if ($presentation === 'graph') {
        $layout->containers = ['main' => ['widgets' => $widgets]];
    }

    $fallbackCalls = 0;
    resolve(RenderHookRegistry::class)->registerCallable(
        RenderHookLocation::MainContent,
        static function (RenderHookContext $context) use (&$fallbackCalls, $viewNamespace, $containerMeta): string {
            throw_unless($context->item instanceof MainContentRenderHookData, RuntimeException::class);
            $fallbackCalls++;

            return View::make($viewNamespace . '::containers', [
                'containers' => $context->item->layout?->containers,
                'graphMeta' => $containerMeta,
            ])->render();
        },
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );
    bindBladeRendererContext($page, $site, $language, $layout, $theme);
    $runtimeManifest = FrontendRuntimeManifestData::forRenderingStrategy(RenderingStrategyEnum::BladeOnly);
    $renderContext = new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: $theme,
        runtimeManifest: $runtimeManifest,
        publicRenderData: new PublicPageRenderData(
            page: $page,
            site: $site,
            language: $language,
            layout: $layout,
            theme: $theme,
            layoutGraph: (object) ['containers' => $graphContainers],
            runtimeManifest: $runtimeManifest,
            resourcePlan: new FrontendResourcePlanData([], [], [], [], [], [], [], 'hydration-test'),
            surrogateKeys: [],
        ),
    );
    View::addNamespace($viewNamespace, $viewPath);
    File::ensureDirectoryExists($viewPath);
    File::put($viewPath . '/layout.blade.php', '<html><body>{!! $slot !!}</body></html>');
    File::put($viewPath . '/containers.blade.php', '@foreach ($containers as $container)<div class="{{ $container["meta"]["html_class"] ?? $graphMeta["html_class"] ?? "" }}">@foreach ($container["widgets"] as $widget){!! $widget["html"] !!}@endforeach</div>@endforeach');

    try {
        $response = (new BladeFrontendResponseRenderer)->render($renderContext);
        throw_unless($response instanceof Response, RuntimeException::class);
        $content = (string) $response->getContent();
        $usesShortcut = $hydration === 'full' && in_array($presentation, ['none', 'landmark'], true);

        expect($content)->toContain('<section>First widget</section>')
            ->toContain('<aside>Second widget</aside>')
            ->not->toContain('data-model-id')
            ->not->toContain('data-field-path')
            ->not->toContain('wire:')
            ->not->toContain('signature=')
            ->and($fallbackCalls)->toBe($usesShortcut ? 0 : 1);

        if ($usesShortcut) {
            expect($content)->toBe("<html><body><p>First introduction</p>\n<section>First widget</section>\n<aside>Second widget</aside></body></html>");
        } elseif ($presentation !== 'none') {
            expect($content)->toContain('class="ppc-shell"');
        }

        resolve(RenderHookRegistry::class)->registerCallable(
            RenderHookLocation::AfterContent,
            static fn (): string => '<div data-model-id="42">Unsafe hook</div>',
            scenario: 'frontend-main-layout',
            target: 'capell::layout.main',
        );
        if (! $usesShortcut) {
            expect(fn (): Symfony\Component\HttpFoundation\Response|Responsable => (new BladeFrontendResponseRenderer)->render($renderContext))
                ->toThrow(RuntimeException::class, 'Public HTML contains an authoring marker.');
        }
    } finally {
        File::deleteDirectory($viewPath);
    }
})->with([
    'mixed widgets and layout class' => ['missing', 'layout', false],
    'mixed widgets without presentation' => ['missing', 'none', false],
    'unhydrated widget in a later container' => ['missing', 'none', true],
    'blank section HTML is unhydrated' => ['blank', 'none', false],
    'fully hydrated widgets and layout class' => ['full', 'layout', false],
    'fully hydrated widgets and graph class' => ['full', 'graph', false],
    'fully hydrated widgets retain the exact shortcut output' => ['full', 'none', false],
    'fully hydrated containers retain the exact shortcut output' => ['full', 'none', true],
    'fully hydrated demo graphs retain neutral main landmark metadata' => ['full', 'landmark', false],
]);

it('renders the layout builder main content for a definition-only theme with containers, never a data-section attribute', function (): void {
    resolve(ThemeRegistry::class)->register(definitionOnlyThemeDefinition('definition-only-with-containers'));

    $site = Site::factory()->make(['name' => 'Fresh Site']);
    $site->setRelation('blueprint', Blueprint::factory()->make());

    $language = Language::factory()->make(['code' => 'en']);
    $theme = Theme::factory()->make(['key' => 'definition-only-with-containers', 'meta' => []]);
    $translation = Translation::factory()->make([
        'title' => 'Container page',
        'content' => '<p>Section pipeline fallback should not render.</p>',
        'meta' => [],
    ]);
    $page = Page::factory()->make(['name' => 'Container page', 'meta' => []]);
    $page->setRelation('translation', $translation);
    $page->setRelation('blueprint', Blueprint::factory()->make());

    resolve(RenderHookRegistry::class)->registerCallable(
        RenderHookLocation::MainContent,
        function (RenderHookContext $context): string {
            expect($context->item)->toBeInstanceOf(MainContentRenderHookData::class);

            return '<section>Layout builder main content</section>';
        },
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );

    $layout = Layout::factory()->make(['key' => 'home']);
    $layout->admin = [
        'system_page_layout' => false,
    ];
    $layout->containers = [
        'main' => [
            'widgets' => [
                ['widget_key' => 'hero'],
            ],
        ],
    ];

    bindBladeRendererContext($page, $site, $language, $layout, $theme);

    $response = (new BladeFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: $theme,
    ));

    throw_unless($response instanceof Response, RuntimeException::class, 'Expected blade renderer to return an HTTP response.');

    expect($response->getContent())
        ->toContain('Layout builder main content')
        ->not->toContain('data-section=');
});

it('renders an empty container layout through the standard content component', function (): void {
    resolve(ThemeRegistry::class)->register(definitionOnlyThemeDefinition('definition-only-without-containers'));

    $site = Site::factory()->make(['name' => 'Fresh Site']);
    $site->setRelation('blueprint', Blueprint::factory()->make());

    $language = Language::factory()->make(['code' => 'en']);
    $theme = Theme::factory()->make(['key' => 'definition-only-without-containers', 'meta' => []]);
    $translation = Translation::factory()->make([
        'title' => 'Untouched page title',
        'content' => '<p>No layout-builder content has been authored yet.</p>',
        'meta' => [],
    ]);
    $page = Page::factory()->make(['name' => 'Untouched page', 'meta' => []]);
    $page->setRelation('translation', $translation);
    $page->setRelation('blueprint', Blueprint::factory()->make());

    $layout = Layout::factory()->make(['key' => 'home']);
    $layout->admin = [
        'system_page_layout' => false,
    ];
    $layout->containers = [];

    bindBladeRendererContext($page, $site, $language, $layout, $theme);

    $response = (new BladeFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: $theme,
    ));

    throw_unless($response instanceof Response, RuntimeException::class, 'Expected blade renderer to return an HTTP response.');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())
        ->toContain('Untouched page title')
        ->not->toContain('data-section=');
});

function definitionOnlyThemeDefinition(string $themeKey, ?string $extends = null): ThemeDefinitionData
{
    return new ThemeDefinitionData(
        key: $themeKey,
        name: $themeKey,
        description: $themeKey,
        package: 'capell-app/theme-' . $themeKey,
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'default',
                name: 'Default',
                description: 'Default preset.',
                previewImage: '/preset.jpg',
            ),
        ],
        runtime: FrontendRuntime::Blade,
        extends: $extends,
    );
}

it('rejects authoring markers returned by the blade markdown response path', function (): void {
    app()->bind('capell.frontend.page-markdown-response', fn (): callable => fn (): Response => response('<div data-model-id="42">Unsafe</div>'));

    (new BladeFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));
})->throws(RuntimeException::class, 'Public HTML contains an authoring marker.');

it('rejects signed admin urls returned by the blade markdown response path', function (): void {
    app()->bind('capell.frontend.page-markdown-response', fn (): callable => fn (): Response => response('<a href="/admin/pages/1/edit?signature=plain-signature">Edit</a>'));

    (new BladeFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: null,
        site: null,
        language: null,
        layout: null,
        theme: null,
    ));
})->throws(RuntimeException::class, 'Public HTML contains a signed admin URL.');

function bindBladeRendererContext(?Pageable $page, ?Site $site, ?Language $language, ?Layout $layout, ?Theme $theme): void
{
    app()->instance(ThemeRuntimeSettings::class, new class implements ThemeRuntimeSettings
    {
        public function activeTheme(): string
        {
            return 'default';
        }

        public function activePreset(): string
        {
            return 'default';
        }

        public function brandProfile(): BrandProfileData
        {
            return new BrandProfileData;
        }

        public function themeOverrides(): array
        {
            return [];
        }
    });

    $context = new class($page, $site, $language, $layout, $theme) implements FrontendContextReader
    {
        /**
         * @var array<string, mixed>
         */
        private array $frontendData = [];

        public function __construct(
            private readonly ?Pageable $page,
            private readonly ?Site $site,
            private readonly ?Language $language,
            private readonly ?Layout $layout,
            private readonly ?Theme $theme,
        ) {}

        public function site(): ?Site
        {
            return $this->site;
        }

        public function language(): ?Language
        {
            return $this->language;
        }

        public function page(): ?Pageable
        {
            return $this->page;
        }

        public function layout(): ?Layout
        {
            return $this->layout;
        }

        public function theme(): ?Theme
        {
            return $this->theme;
        }

        public function params(): array
        {
            return [];
        }

        public function slug(): ?string
        {
            return null;
        }

        public function isError(): bool
        {
            return false;
        }

        public function setFrontendData(string $key, mixed $value): self
        {
            $this->frontendData[$key] = $value;

            return $this;
        }

        public function getFrontendData(?string $key = null): mixed
        {
            return $key === null ? $this->frontendData : ($this->frontendData[$key] ?? null);
        }

        public function renderPayload(): FrontendRenderPayload
        {
            return FrontendRenderPayload::fromBag($this->frontendData);
        }
    };

    app()->instance(FrontendContextReader::class, $context);
}

it('rejects authoring markers from the primary blade render path', function (): void {
    app()->instance(ThemeRuntimeSettings::class, new class implements ThemeRuntimeSettings
    {
        public function activeTheme(): string
        {
            return 'missing-theme';
        }

        public function activePreset(): string
        {
            return 'default';
        }

        public function brandProfile(): BrandProfileData
        {
            return new BrandProfileData;
        }

        public function themeOverrides(): array
        {
            return [];
        }
    });

    View::addNamespace('capell-renderer-test', resource_path('views/capell-renderer-test'));
    File::ensureDirectoryExists(resource_path('views/capell-renderer-test'));
    File::put(resource_path('views/capell-renderer-test/master.blade.php'), '<div>Master</div>');
    File::put(resource_path('views/capell-renderer-test/layout.blade.php'), '<html><body data-model-id="42">{!! $slot !!}</body></html>');

    $layout = new Layout;
    $layout->meta = [
        'master_file' => 'capell-renderer-test::master',
        'layout_file' => 'capell-renderer-test::layout',
    ];

    try {
        (new BladeFrontendResponseRenderer)->render(new FrontendRenderContextData(
            page: Page::factory()->make(),
            site: null,
            language: null,
            layout: $layout,
            theme: null,
        ));
    } finally {
        File::deleteDirectory(resource_path('views/capell-renderer-test'));
    }
})->throws(RuntimeException::class, 'Public HTML contains an authoring marker.');
