<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Actions;

use Capell\Core\ThemeStudio\Contracts\ThemePageAdapter;
use Capell\Core\ThemeStudio\Contracts\ThemeRuntimeSettings;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Theme\ThemePageAdapterRegistry;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class RenderCurrentThemePageAction
{
    use AsObject;

    /**
     * @param  array<string, array<string, mixed>>|null  $themeOverrides
     */
    public function handle(
        ?string $activeTheme = null,
        ?string $activePreset = null,
        ?BrandProfileData $brand = null,
        ?array $themeOverrides = null,
    ): string {
        $settings = resolve(ThemeRuntimeSettings::class);
        $resolvedActiveTheme = $activeTheme ?? $settings->activeTheme();
        $pageAdapter = resolve(ThemePageAdapterRegistry::class)
            ->adapterFor($resolvedActiveTheme, resolve(ThemePageAdapter::class));
        $page = $pageAdapter->currentPage();

        return RenderThemePageAction::run(
            page: $page,
            activeTheme: $resolvedActiveTheme,
            activePreset: $activePreset ?? $settings->activePreset(),
            brand: $brand ?? $settings->brandProfile(),
            themeOverrides: $themeOverrides ?? $settings->themeOverrides(),
        );
    }
}
