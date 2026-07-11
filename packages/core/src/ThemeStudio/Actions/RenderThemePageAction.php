<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Actions;

use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemePageData;
use Capell\Core\ThemeStudio\Preview\ThemePreviewContext;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @deprecated Part of the section-rendering pipeline being replaced by
 * x-capell::layout + layout-builder widget rendering.
 */
class RenderThemePageAction
{
    use AsObject;

    /**
     * @param  array<string, array<string, mixed>>  $themeOverrides
     */
    public function handle(
        ThemePageData $page,
        string $activeTheme,
        string $activePreset,
        ?BrandProfileData $brand = null,
        array $themeOverrides = [],
        ?ThemePreviewContext $previewContext = null,
    ): string {
        $runtime = ResolveThemeRuntimeAction::run(
            activeTheme: $activeTheme,
            activePreset: $activePreset,
            brand: $brand ?? $page->brand,
            themeOverrides: $themeOverrides,
            previewContext: $previewContext,
        );

        return $runtime->renderer->render(new ThemePageData(
            title: $page->title,
            brand: $runtime->brand,
            sections: $page->sections,
            navigation: $page->navigation,
            footer: $page->footer,
        ));
    }
}
