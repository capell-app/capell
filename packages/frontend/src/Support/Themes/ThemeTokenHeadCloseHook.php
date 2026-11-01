<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Themes;

use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Actions\ResolveThemeRuntimeAction;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Capell\Core\ThemeStudio\Typography\ThemeFontCatalogue;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\RenderHookExtensionInterface;
use Capell\Frontend\Data\RenderHookContext;
use Capell\Frontend\Support\Security\HeadContentSanitizer;
use Illuminate\Contracts\Foundation\Application;

final class ThemeTokenHeadCloseHook implements RenderHookExtensionInterface
{
    /** @var array<string, string|null> */
    private array $cssByPath = [];

    public function __construct(
        private readonly Application $application,
        private readonly ThemeRegistry $themeRegistry,
        private readonly ThemeTokenStore $themeTokenStore,
    ) {}

    public function render(RenderHookContext $context): string
    {
        if (! $this->application->bound(ThemeRuntimeSettings::class)) {
            return '';
        }

        $settings = $this->application->make(ThemeRuntimeSettings::class);
        $theme = $this->application->bound(FrontendContextReader::class)
            ? $this->application->make(FrontendContextReader::class)->theme()
            : null;
        $activeTheme = $theme instanceof Theme ? $theme->key : $settings->activeTheme();
        $activePreset = $theme instanceof Theme
            ? data_get($theme->meta, 'editor.preset.active', $settings->activePreset())
            : $settings->activePreset();
        $themeOverrides = $settings->themeOverrides();

        if ($theme instanceof Theme) {
            $savedTokens = data_get($theme->meta, 'editor.tokens', []);

            if (is_array($savedTokens)) {
                $themeOverrides[$theme->key] = [
                    ...($themeOverrides[$theme->key] ?? []),
                    ...array_filter(
                        $savedTokens,
                        static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
                        ARRAY_FILTER_USE_BOTH,
                    ),
                ];
            }
        }

        if (! is_string($activePreset) || $activePreset === '') {
            $activePreset = $settings->activePreset();
        }

        if (! $this->themeRegistry->has($activeTheme)) {
            return '';
        }

        $runtime = ResolveThemeRuntimeAction::run(
            activeTheme: $activeTheme,
            activePreset: $activePreset,
            brand: $settings->brandProfile(),
            themeOverrides: $themeOverrides,
        );

        $preloads = $theme instanceof Theme && data_get($theme->meta, 'frontend_runtime.uses_foundation_theme_css') === false
            ? ''
            : $this->fontPreloads($runtime->brand);

        if ($runtime->tokenCssPath === null) {
            return $preloads;
        }

        if (! array_key_exists($runtime->tokenCssPath, $this->cssByPath)) {
            $this->cssByPath[$runtime->tokenCssPath] = $this->readCss($runtime->tokenCssPath);
        }

        $css = $this->cssByPath[$runtime->tokenCssPath];

        if ($css !== null) {
            return $preloads . '<style data-capell-theme-tokens>' . HeadContentSanitizer::css($css) . '</style>';
        }

        return $preloads . '<link rel="stylesheet" href="' . e($this->themeTokenStore->publicUrl($runtime->tokenCssPath)) . '">';
    }

    /**
     * Preload the heading and body font files so text paints in its final face
     * without a swap. The paths are fixed catalogue literals, never setting values.
     */
    private function fontPreloads(BrandProfileData $brand): string
    {
        $html = '';

        foreach (array_unique([$brand->headingFont, $brand->bodyFont]) as $key) {
            $path = ThemeFontCatalogue::preloadPath($key);

            if ($path !== null) {
                $html .= '<link rel="preload" as="font" type="font/woff2" crossorigin href="' . e($path) . '">';
            }
        }

        return $html;
    }

    private function readCss(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $css = file_get_contents($path);

        return is_string($css) && $css !== '' ? $css : null;
    }
}
