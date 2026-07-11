<?php

declare(strict_types=1);

use Capell\Core\Enums\FrontendRuntime;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\MainContentRenderHookData;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Enums\RenderingStrategyEnum;
use Capell\Frontend\Support\Render\LivewireFrontendResponseRenderer;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Support\State\FrontendState;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Covers the same "definition-only theme" scenarios as
 * BladeFrontendResponseRendererTest, but driven through
 * LivewireFrontendResponseRenderer — the actual Livewire runtime entrypoint
 * that resolves the real `Capell\Frontend\Livewire\Page\Page` component
 * (via `resolve('livewire')->new($component)`) and renders
 * livewire/page/page.blade.php, rather than through
 * BladeFrontendResponseRenderer. Livewire and BladeOnly are two separate
 * rendering runtimes, and page.blade.php's `hasRenderer($key)` gate is only
 * exercised on this path — a rendererless theme must never crash the
 * Livewire route and must never emit a `data-section=` attribute.
 */
it('renders layout builder main content through the livewire page component for a definition-only theme with containers', function (): void {
    resolve(ThemeRegistry::class)->register(livewireRendererlessThemeDefinition('definition-only-livewire-with-containers'));

    $language = Language::factory()->english()->create();
    $theme = Theme::factory()->create([
        'key' => 'definition-only-livewire-with-containers',
        'meta' => [],
    ]);
    $site = Site::factory()
        ->theme($theme)
        ->withTranslations($language, siteDomainData: [
            'default' => true,
            'domain' => 'localhost',
            'scheme' => 'http',
            'path' => null,
        ])
        ->create(['language_id' => $language->id]);
    $layout = Layout::factory()->site($site)->create();
    $layout->admin = ['system_page_layout' => false];
    $layout->containers = [
        'main' => [
            'widgets' => [
                ['widget_key' => 'hero'],
            ],
        ],
    ];
    $layout->save();

    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, [
            'title' => 'Container page',
            'content' => '<p>Section pipeline fallback should not render.</p>',
        ])
        ->create([
            'meta' => ['rendering_strategy' => RenderingStrategyEnum::FullLivewire->value],
        ]);
    $page->load('pageUrl.siteDomain');

    resolve(RenderHookRegistry::class)->registerCallable(
        RenderHookLocation::MainContent,
        function (RenderHookContext $context): string {
            expect($context->item)->toBeInstanceOf(MainContentRenderHookData::class);

            return '<section>Layout builder main content</section>';
        },
        scenario: 'frontend-main-layout',
        target: 'capell::layout.main',
    );

    app()->instance('request', Request::create($page->pageUrl->full_url, Request::METHOD_GET));

    resolve(FrontendState::class)
        ->withSite($site)
        ->withLanguage($language)
        ->withPage($page)
        ->withLayout($layout)
        ->withTheme($theme)
        ->withDomain($site->siteDomains->first())
        ->withRelativePath($page->pageUrl->url)
        ->setEffectiveUrl($page->pageUrl->url);

    $response = (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: $theme,
    ));

    throw_unless($response instanceof Response, RuntimeException::class, 'Expected livewire renderer to return an HTTP response.');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())
        ->toContain('Layout builder main content')
        ->not->toContain('data-section=');
});

it('never crashes the livewire page component for a rendererless theme with an empty container layout', function (): void {
    resolve(ThemeRegistry::class)->register(livewireRendererlessThemeDefinition('definition-only-livewire-without-containers'));

    $language = Language::factory()->english()->create();
    $theme = Theme::factory()->create([
        'key' => 'definition-only-livewire-without-containers',
        'meta' => [],
    ]);
    $site = Site::factory()
        ->theme($theme)
        ->withTranslations($language, siteDomainData: [
            'default' => true,
            'domain' => 'localhost',
            'scheme' => 'http',
            'path' => null,
        ])
        ->create(['language_id' => $language->id]);
    $layout = Layout::factory()->site($site)->create();
    $layout->admin = ['system_page_layout' => false];
    $layout->containers = [];
    $layout->save();

    $page = Page::factory()
        ->site($site)
        ->layout($layout)
        ->withTranslations($language, [
            'title' => 'Untouched page title',
            'content' => '<p>No layout-builder content has been authored yet.</p>',
        ])
        ->create([
            'meta' => ['rendering_strategy' => RenderingStrategyEnum::FullLivewire->value],
        ]);
    $page->load('pageUrl.siteDomain');

    app()->instance('request', Request::create($page->pageUrl->full_url, Request::METHOD_GET));

    resolve(FrontendState::class)
        ->withSite($site)
        ->withLanguage($language)
        ->withPage($page)
        ->withLayout($layout)
        ->withTheme($theme)
        ->withDomain($site->siteDomains->first())
        ->withRelativePath($page->pageUrl->url)
        ->setEffectiveUrl($page->pageUrl->url);

    $response = (new LivewireFrontendResponseRenderer)->render(new FrontendRenderContextData(
        page: $page,
        site: $site,
        language: $language,
        layout: $layout,
        theme: $theme,
    ));

    throw_unless($response instanceof Response, RuntimeException::class, 'Expected livewire renderer to return an HTTP response.');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())
        ->toContain('Untouched page title')
        ->not->toContain('data-section=');
});

function livewireRendererlessThemeDefinition(string $themeKey): ThemeDefinitionData
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
        runtime: FrontendRuntime::Livewire,
    );
}
