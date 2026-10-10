<?php

declare(strict_types=1);

use Capell\Core\ThemeStudio\Actions\ResolveThemeRuntimeAction;
use Capell\Core\ThemeStudio\Assets\ThemeTokenRenderer;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Tests\Support\StylesheetRuntime;
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

    expect(StylesheetRuntime::declarations($css)['tokens'])->toMatchArray([
        '--theme-radius' => 'md',
        '--theme-radius-value' => '0.5rem',
        '--theme-surface' => '#ffffff',
        '--theme-foreground' => '#111827',
        '--theme-heading-scale' => 'expressive',
        '--theme-heading-scale-ratio' => '1.25',
        '--theme-card-density' => 'compact',
        '--theme-card-density-gap' => '0.75rem',
        '--theme-overlay-treatment' => 'strong',
        '--theme-overlay-opacity' => '0.65',
    ]);
});

it('sanitizes unsafe css token values before rendering', function (): void {
    $brand = new BrandProfileData(surfaceColor: 'url(https://example.test/image.png)');

    expect(StylesheetRuntime::declarations((new ThemeTokenRenderer)->css($brand))['tokens']['--theme-surface'])->toBe('#ffffff');
});

it('falls back when color tokens are css-safe but not valid colors', function (): void {
    $brand = new BrandProfileData(surfaceColor: 'not-a-color');

    expect(StylesheetRuntime::declarations((new ThemeTokenRenderer)->css($brand))['tokens']['--theme-surface'])->toBe('#ffffff');
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

        $stylesheet = StylesheetRuntime::inspect($path);
        expect($stylesheet['tokens'])->toHaveKey('--theme-primary')
            ->and($stylesheet['rules'][0]['root'])->toBeTrue()
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
            ->and(StylesheetRuntime::inspect((string) $runtime->tokenCssPath)['tokens']['--theme-foreground'])->toBe('#111827');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('preserves theme identity tokens while repairing an unsafe contrast pair', function (): void {
    $directory = storage_path('framework/testing/theme-tokens-' . Str::uuid()->toString());

    app()->instance(ThemeTokenStore::class, new ThemeTokenStore($directory));
    resolve(ThemeRegistry::class)->register(
        new ThemeDefinitionData(
            key: 'contrast-repair-theme',
            name: 'Contrast Repair Theme',
            description: 'Theme runtime contrast repair test.',
            package: 'capell-app/contrast-repair-theme',
            previewImage: '/preview.jpg',
            tags: [],
            bestFit: [],
            presets: [
                new ThemePresetData(
                    key: 'default',
                    name: 'Default',
                    description: 'Default preset.',
                    previewImage: '/preset.jpg',
                    values: [
                        'primaryColor' => '#0a0a0a',
                        'accentColor' => '#ff2b00',
                        'neutralColor' => '#4b4b4b',
                        'surfaceColor' => '#f4f3ef',
                        'foregroundColor' => '#0a0a0a',
                        'radius' => 'none',
                    ],
                ),
            ],
        ),
    );

    $runtime = ResolveThemeRuntimeAction::run(
        activeTheme: 'contrast-repair-theme',
        activePreset: 'default',
        brand: new BrandProfileData,
    );

    try {
        $css = File::get((string) $runtime->tokenCssPath);

        expect($runtime->tokenIssues)
            ->toHaveCount(1)
            ->and($runtime->tokenIssues[0])->toContain('accent/surface')
            ->and(StylesheetRuntime::declarations($css)['tokens'])->toMatchArray([
                '--theme-primary' => '#0a0a0a',
                '--theme-accent' => '#0a0a0a',
                '--theme-accent-contrast' => '#ffffff',
                '--theme-neutral' => '#4b4b4b',
                '--theme-surface' => '#f4f3ef',
                '--theme-radius' => 'none',
            ]);
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

        $stylesheet = StylesheetRuntime::declarations($css);
        expect($stylesheet['tokens'])->toMatchArray([
            '--theme-glass-depth' => 'prismatic',
            '--theme-radius-value' => '0.5rem',
        ])->not->toHaveKeys(['--theme-undeclared-token', '--theme-x'])
            ->and($stylesheet['rules'])->toHaveCount(1);
        foreach ($stylesheet['rules'][0]['declarations'] as $declaration) {
            expect($declaration['property'])->toBe('custom');
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

it('pairs rendered accent and primary backgrounds with readable labels', function (string $background, string $expected): void {
    $css = (new ThemeTokenRenderer)->css(new BrandProfileData(primaryColor: $background, accentColor: $background));

    expect(StylesheetRuntime::declarations($css)['tokens'])->toMatchArray([
        '--theme-primary-contrast' => $expected, '--theme-accent-contrast' => $expected,
    ]);
})->with([
    'dark replacement' => ['#1c2530', '#ffffff'],
    'white background' => ['#ffffff', '#000000'],
    'amber background' => ['#f59e0b', '#000000'],
    'mid-tone background' => ['#777777', '#000000'],
]);
