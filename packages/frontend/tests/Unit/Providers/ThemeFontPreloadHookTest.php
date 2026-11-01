<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Capell\Frontend\Tests\Fixtures\HasFrontendRenderData;
use Illuminate\Support\Facades\File;

uses()->group('theme');

/** @param array<string, mixed> $meta */
function renderFontPreloadHeadClose(string $headingFont, string $bodyFont, array $meta = []): string
{
    Blueprint::factory()->theme()->default()->create();

    $theme = Theme::factory()->createOne(['key' => 'font-theme', 'meta' => $meta]);
    $directory = storage_path('app/testing/font-preload-' . bin2hex(random_bytes(4)));
    File::ensureDirectoryExists($directory);

    app()->instance(ThemeTokenStore::class, new ThemeTokenStore($directory));
    app()->instance(ThemeRuntimeSettings::class, new class implements ThemeRuntimeSettings
    {
        public function activeTheme(): string
        {
            return 'font-theme';
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
    app()->instance(FrontendContextReader::class, new class($theme) implements FrontendContextReader
    {
        use HasFrontendRenderData;

        public function __construct(private readonly Theme $theme) {}

        public function site(): ?Site
        {
            return null;
        }

        public function language(): ?Language
        {
            return null;
        }

        public function page(): ?Pageable
        {
            return null;
        }

        public function layout(): ?Layout
        {
            return null;
        }

        public function theme(): Theme
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
    });

    resolve(ThemeRegistry::class)->register(new ThemeDefinitionData(
        key: 'font-theme',
        name: 'Font Theme',
        description: 'Font preload test theme.',
        package: 'capell-app/font-theme',
        previewImage: '/preview.jpg',
        tags: [],
        bestFit: [],
        presets: [
            new ThemePresetData(
                key: 'default',
                name: 'Default',
                description: 'Default preset.',
                previewImage: '/preset.jpg',
                values: ['headingFont' => $headingFont, 'bodyFont' => $bodyFont],
            ),
        ],
    ));

    try {
        return resolve(RenderHookRegistry::class)->renderAll(RenderHookLocation::HeadClose);
    } finally {
        File::deleteDirectory($directory);
    }
}

it('preloads the bundled font files a theme setting resolves to, once each', function (): void {
    $html = renderFontPreloadHeadClose('fraunces', 'inter');

    expect($html)
        ->toContain('<link rel="preload" as="font" type="font/woff2" crossorigin href="/vendor/capell-theme-foundation/fonts/Fraunces-Variable.woff2">')
        ->toContain('href="/vendor/capell-theme-foundation/fonts/Inter-Variable.woff2"')
        ->and(substr_count($html, 'rel="preload"'))->toBe(2)
        ->and($html)->toContain("'Fraunces', Georgia, serif");
});

it('emits one preload when heading and body share a font', function (): void {
    expect(substr_count(renderFontPreloadHeadClose('sora', 'sora'), 'rel="preload"'))->toBe(1);
});

it('emits no preload for system fonts', function (): void {
    expect(renderFontPreloadHeadClose('system', 'system'))->not->toContain('rel="preload"');
});

it('emits no preload for an unknown or hostile font key', function (): void {
    $html = renderFontPreloadHeadClose('x"><script>alert(1)</script>', 'inter');

    expect($html)->not->toContain('<script>')
        ->and(substr_count($html, 'rel="preload"'))->toBe(1);
});

it('skips the preload when the theme opts out of the Foundation stylesheet', function (): void {
    $html = renderFontPreloadHeadClose('fraunces', 'inter', ['frontend_runtime' => ['uses_foundation_theme_css' => false]]);

    expect($html)->not->toContain('rel="preload"');
});
