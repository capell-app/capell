<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Actions\ResolveThemeRuntimeAction;
use Capell\Core\ThemeStudio\Assets\ThemeTokenRenderer;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('renders expanded controlled theme tokens with safe fallbacks', function (): void {
    $brand = new BrandProfileData(
        radius: 'unsupported',
        surfaceColor: '#ffffff',
        foregroundColor: '#111827',
        headingScale: 'expressive',
        cardDensity: 'compact',
        overlayTreatment: 'strong',
    );

    $css = (new ThemeTokenRenderer)->css($brand);

    expect($css)->toContain('--theme-radius: md;')
        ->toContain('--theme-radius-value: 0.5rem;')
        ->toContain('--theme-surface: #ffffff;')
        ->toContain('--theme-foreground: #111827;')
        ->toContain('--theme-heading-scale: expressive;')
        ->toContain('--theme-heading-scale-ratio: 1.25;')
        ->toContain('--theme-card-density: compact;')
        ->toContain('--theme-card-density-gap: 0.75rem;')
        ->toContain('--theme-overlay-treatment: strong;')
        ->toContain('--theme-overlay-opacity: 0.65;');
});

it('sanitizes unsafe css token values before rendering', function (): void {
    $brand = new BrandProfileData(surfaceColor: 'url(https://example.test/image.png)');

    expect((new ThemeTokenRenderer)->css($brand))->toContain('--theme-surface: #ffffff;');
});

it('falls back when color tokens are css-safe but not valid colors', function (): void {
    $brand = new BrandProfileData(surfaceColor: 'not-a-color');

    expect((new ThemeTokenRenderer)->css($brand))->toContain('--theme-surface: #ffffff;');
});

it('reports inaccessible foreground and surface contrast', function (): void {
    $issues = (new ThemeTokenRenderer)->contrastIssues(new BrandProfileData(
        surfaceColor: '#ffffff',
        foregroundColor: '#fefefe',
    ));

    expect($issues)->toHaveCount(1);
});

it('reports invalid foreground and surface contrast tokens', function (): void {
    $issues = (new ThemeTokenRenderer)->contrastIssues(new BrandProfileData(
        surfaceColor: 'not-a-color',
        foregroundColor: '#111827',
    ));

    expect($issues)->not->toBeEmpty()
        ->and($issues[0])->toContain('invalid color');
});

it('publishes token css without leaving partial files', function (): void {
    $directory = storage_path('framework/testing/theme-tokens-' . Str::uuid()->toString());

    try {
        $path = new ThemeTokenStore($directory)->put('atomic-theme', 'default', new BrandProfileData);

        expect(File::get($path))->toStartWith(':root {')
            ->and(File::glob($directory . '/*.tmp'))->toBe([])
            ->and(File::files($directory))->toHaveCount(1);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('returns fallback token css and exposes token issues for invalid runtime profiles', function (): void {
    $directory = storage_path('framework/testing/theme-tokens-' . Str::uuid()->toString());

    app()->instance(ThemeTokenStore::class, new ThemeTokenStore($directory));
    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'test-theme',
            name: 'Test Theme',
            description: 'Theme runtime fallback test.',
            package: 'capell-app/test-theme',
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
        ),
    );

    $runtime = ResolveThemeRuntimeAction::run(
        activeTheme: 'test-theme',
        activePreset: 'default',
        brand: new BrandProfileData(
            surfaceColor: '#ffffff',
            foregroundColor: '#ffffff',
        ),
    );

    try {
        expect($runtime->tokenIssues)->not->toBeEmpty()
            ->and($runtime->tokenCssPath)->not->toBeNull()
            ->and(File::get((string) $runtime->tokenCssPath))->toContain('--theme-foreground: #111827;');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('continues resolving runtime data when theme token css cannot be written', function (): void {
    app()->instance(ThemeTokenStore::class, new class extends ThemeTokenStore
    {
        public function put(string $themeKey, string $presetKey, BrandProfileData $brand): string
        {
            throw new RuntimeException('Token directory is not writable.');
        }
    });

    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'unwritable-token-theme',
            name: 'Unwritable Token Theme',
            description: 'Theme runtime writable directory test.',
            package: 'capell-app/unwritable-token-theme',
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
        ),
    );

    $runtime = ResolveThemeRuntimeAction::run(
        activeTheme: 'unwritable-token-theme',
        activePreset: 'default',
        brand: new BrandProfileData,
    );

    expect($runtime->tokenCssPath)->toBeNull()
        ->and($runtime->assetKey)->not->toBe('');
});

it('renders declared theme editor extras and rejects values outside their closed vocabulary', function (): void {
    $directory = storage_path('framework/testing/theme-tokens-' . Str::uuid()->toString());

    app()->instance(ThemeTokenStore::class, new ThemeTokenStore($directory));
    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'identity-token-theme',
            name: 'Identity Token Theme',
            description: 'Theme runtime identity token test.',
            package: 'capell-app/identity-token-theme',
            previewImage: '/preview.jpg',
            tags: [],
            bestFit: [],
            presets: [
                new ThemePresetData(
                    key: 'default',
                    name: 'Default',
                    description: 'Default preset.',
                    previewImage: '/preset.jpg',
                    values: ['glassDepth' => 'balanced'],
                ),
            ],
            frontend: [
                'editor' => [
                    'groups' => ['identity' => ['glassDepth']],
                    'tokens' => [
                        'glassDepth' => ['options' => ['restrained', 'balanced', 'prismatic']],
                        'x; } body { displayNone' => ['options' => ['unsafe']],
                        'radiusValue' => ['options' => ['999px']],
                    ],
                ],
            ],
        ),
    );

    try {
        $runtime = ResolveThemeRuntimeAction::run(
            activeTheme: 'identity-token-theme',
            activePreset: 'default',
            brand: new BrandProfileData,
            themeOverrides: [
                'identity-token-theme' => [
                    'glassDepth' => 'prismatic',
                    'undeclaredToken' => 'unsafe; } body { display: none',
                    'x; } body { displayNone' => 'unsafe',
                    'radiusValue' => '999px',
                ],
            ],
        );

        $css = File::get((string) $runtime->tokenCssPath);

        expect($css)
            ->toContain('--theme-glass-depth: prismatic;')
            ->toContain('--theme-radius-value: 0.5rem;')
            ->not->toContain('undeclared-token')
            ->not->toContain('--theme-x;')
            ->not->toContain('--theme-radius-value: 999px;')
            ->not->toContain('display: none');
    } finally {
        File::deleteDirectory($directory);
    }
});
