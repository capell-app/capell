<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Actions\ResolveBrandProfileAction;
use Capell\Core\ThemeStudio\Contracts\SectionRenderer;
use Capell\Core\ThemeStudio\Contracts\ThemeSection;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeOverrideData;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Exceptions\SectionRendererNotFoundException;
use Capell\Core\ThemeStudio\Rendering\BladeThemeRenderer;
use Capell\Core\ThemeStudio\Rendering\ViewSectionRenderer;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Facades\File;

it('renders missing child theme sections through the parent theme chain', function (): void {
    $registry = themeRendererInheritanceRegistry();
    app()->instance(ThemeRegistry::class, $registry);

    $registry->register(
        definition: themeRendererInheritanceDefinition('default'),
        themeRenderer: new BladeThemeRenderer('default', 'missing-layout', []),
        sectionRenderers: [
            new ThemeRendererInheritanceSectionRenderer('default', 'hero'),
        ],
    );
    $registry->register(
        definition: themeRendererInheritanceDefinition('local-services', 'default'),
        themeRenderer: new BladeThemeRenderer('local-services', 'missing-layout', []),
        sectionRenderers: [],
    );

    $html = $registry->renderer('local-services')->render(themeRendererInheritancePage([
        new ThemeRendererInheritanceSection('hero'),
    ]));

    expect($html)->toBe('<section data-theme="default" data-section="hero"></section>');
});

it('prefers child theme section renderers before parent renderers', function (): void {
    $registry = themeRendererInheritanceRegistry();
    app()->instance(ThemeRegistry::class, $registry);

    $registry->register(
        definition: themeRendererInheritanceDefinition('default'),
        themeRenderer: new BladeThemeRenderer('default', 'missing-layout', []),
        sectionRenderers: [
            new ThemeRendererInheritanceSectionRenderer('default', 'hero'),
        ],
    );
    $registry->register(
        definition: themeRendererInheritanceDefinition('portfolio', 'default'),
        themeRenderer: new BladeThemeRenderer('portfolio', 'missing-layout', []),
        sectionRenderers: [
            new ThemeRendererInheritanceSectionRenderer('portfolio', 'hero'),
        ],
    );

    $html = $registry->renderer('portfolio')->render(themeRendererInheritancePage([
        new ThemeRendererInheritanceSection('hero'),
    ]));

    expect($html)->toBe('<section data-theme="portfolio" data-section="hero"></section>');
});

it('resolves fallback section keys through the parent theme chain', function (): void {
    $registry = themeRendererInheritanceRegistry();
    app()->instance(ThemeRegistry::class, $registry);

    $registry->register(
        definition: themeRendererInheritanceDefinition('default'),
        themeRenderer: new BladeThemeRenderer('default', 'missing-layout', []),
        sectionRenderers: [
            new ThemeRendererInheritanceSectionRenderer('default', 'content-listing'),
        ],
    );
    $registry->register(
        definition: themeRendererInheritanceDefinition('knowledge', 'default'),
        themeRenderer: new BladeThemeRenderer('knowledge', 'missing-layout', []),
        sectionRenderers: [],
    );

    $html = $registry->renderer('knowledge')->render(themeRendererInheritancePage([
        new ThemeRendererInheritanceSection('resource-library', 'content-listing'),
    ]));

    expect($html)->toBe('<section data-theme="default" data-section="resource-library"></section>');
});

it('fails loudly when neither child nor parent theme can render a section', function (): void {
    $registry = themeRendererInheritanceRegistry();
    app()->instance(ThemeRegistry::class, $registry);

    $registry->register(
        definition: themeRendererInheritanceDefinition('default'),
        themeRenderer: new BladeThemeRenderer('default', 'missing-layout', []),
        sectionRenderers: [],
    );
    $registry->register(
        definition: themeRendererInheritanceDefinition('education', 'default'),
        themeRenderer: new BladeThemeRenderer('education', 'missing-layout', []),
        sectionRenderers: [],
    );

    expect(fn (): string => $registry->renderer('education')->render(themeRendererInheritancePage([
        new ThemeRendererInheritanceSection('course-catalog'),
    ])))->toThrow(SectionRendererNotFoundException::class, 'Theme [education] cannot render section [course-catalog].');
});

it('renders view-backed sections and falls back safely when a section view is unavailable', function (): void {
    $viewPath = resource_path('views/testing/theme-section.blade.php');
    File::ensureDirectoryExists(dirname($viewPath));
    File::put($viewPath, '<h1>{{ $heading }}</h1>');

    try {
        $viewRenderer = new ViewSectionRenderer('default', 'hero', 'testing.theme-section', extraViewData: [
            'heading' => 'Injected Studio',
            'eyebrow' => 'Renderer data',
        ]);
        $fallbackRenderer = new ViewSectionRenderer('default"><script>', 'hero', 'testing.missing-section');

        expect($viewRenderer->themeKey())->toBe('default')
            ->and($viewRenderer->sectionKey())->toBe('hero')
            ->and($viewRenderer->render(new ThemeRendererInheritanceSection('hero', data: ['heading' => 'Hello Studio'])))->toContain('<h1>Injected Studio</h1>')
            ->and($fallbackRenderer->render(new ThemeRendererInheritanceSection('hero"><script>')))->toBe('<section data-theme="default&quot;&gt;&lt;script&gt;" data-section="hero&quot;&gt;&lt;script&gt;"></section>');

        expect(fn (): string => new ViewSectionRenderer('default', 'hero', 'testing.missing-section', failLoudly: true)
            ->render(new ThemeRendererInheritanceSection('hero')))->toThrow(InvalidArgumentException::class, 'View [testing.missing-section] not found.');
    } finally {
        File::delete($viewPath);
    }
});

it('resolves brand profile values from theme presets before explicit overrides', function (): void {
    $definition = new ThemeDefinitionData(
        key: 'studio-theme',
        name: 'Studio Theme',
        description: 'Studio Theme',
        package: 'capell-app/theme-saas',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'editorial',
                name: 'Editorial',
                description: 'Editorial',
                previewImage: '/preview.jpg',
                values: [
                    'primaryColor' => '#112233',
                    'spacing' => 'spacious',
                    'headingFont' => 'playfair',
                ],
            ),
        ],
        includedSections: [],
    );

    $brand = ResolveBrandProfileAction::run(
        brand: new BrandProfileData(primaryColor: '#000000', accentColor: '#111111', spacing: 'balanced'),
        definition: $definition,
        override: new ThemeOverrideData(
            themeKey: 'studio-theme',
            presetKey: 'editorial',
            values: [
                'accentColor' => '#445566',
                'spacing' => 'compact',
            ],
        ),
    );

    expect($brand->primaryColor)->toBe('#112233')
        ->and($brand->accentColor)->toBe('#445566')
        ->and($brand->spacing)->toBe('compact')
        ->and($brand->headingFont)->toBe('playfair');
});

function themeRendererInheritanceRegistry(): ThemeRegistry
{
    return new ThemeRegistry;
}

function themeRendererInheritanceDefinition(string $themeKey, ?string $extends = null): ThemeDefinitionData
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
                key: 'base',
                name: 'Base',
                description: 'Base',
                previewImage: '/preview.jpg',
                values: [],
            ),
        ],
        includedSections: [],
        extends: $extends,
    );
}

/**
 * @param  array<int, ThemeSection>  $sections
 */
function themeRendererInheritancePage(array $sections): ThemePageData
{
    return new ThemePageData(
        title: 'Theme inheritance',
        brand: new BrandProfileData,
        sections: $sections,
    );
}

final class ThemeRendererInheritanceSection implements ThemeSection
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private readonly string $key,
        private readonly ?string $fallbackKey = null,
        private readonly array $data = [],
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function fallbackKey(): ?string
    {
        return $this->fallbackKey;
    }

    public function toViewData(): array
    {
        return $this->data;
    }
}

final class ThemeRendererInheritanceSectionRenderer implements SectionRenderer
{
    public function __construct(
        private readonly string $themeKey,
        private readonly string $sectionKey,
    ) {}

    public function themeKey(): string
    {
        return $this->themeKey;
    }

    public function sectionKey(): string
    {
        return $this->sectionKey;
    }

    public function render(ThemeSection $section): string
    {
        return sprintf(
            '<section data-theme="%s" data-section="%s"></section>',
            $this->themeKey,
            $section->key(),
        );
    }
}
