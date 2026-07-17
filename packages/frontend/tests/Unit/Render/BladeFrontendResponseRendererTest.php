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
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\MainContentRenderHookData;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\BladeFrontendResponseRenderer;
use Capell\Frontend\Support\Render\FrontendResponseRendererRegistry;
use Capell\Frontend\Support\Render\RenderHookRegistry;
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

    resolve(RenderHookRegistry::class)->registerCallable(
        RenderHookLocation::MainContent,
        function (RenderHookContext $context): string {
            expect($context->item)->toBeInstanceOf(MainContentRenderHookData::class);

            return '<section>Layout builder hook output</section>';
        },
        scenario: 'frontend-main-layout',
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

    View::addNamespace('capell-renderer-test', resource_path('views/capell-renderer-test'));
    File::ensureDirectoryExists(resource_path('views/capell-renderer-test'));
    File::put(resource_path('views/capell-renderer-test/layout.blade.php'), '<html><body>{!! $slot !!}</body></html>');

    try {
        $response = (new BladeFrontendResponseRenderer)->render(new FrontendRenderContextData(
            page: $page,
            site: $site,
            language: $language,
            layout: $layout,
            theme: $theme,
        ));
    } finally {
        File::deleteDirectory(resource_path('views/capell-renderer-test'));
    }

    throw_unless($response instanceof Response, RuntimeException::class, 'Expected blade renderer to return an HTTP response.');

    expect($response->getContent())
        ->toContain('Layout builder hook output')
        ->not->toContain('default-theme-shell')
        ->not->toContain('Theme Studio fallback should not render');
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
