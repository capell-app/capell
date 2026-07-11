<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Theme;

use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Rendering\BladeThemeRenderer;
use Capell\Core\ThemeStudio\Rendering\ViewSectionRenderer;
use Illuminate\Support\Facades\View;

final class ThemePackageRegistrar
{
    public function __construct(private readonly ThemeRegistry $registry) {}

    /**
     * @param  array<int, string>  $viewPaths
     * @param  array<string, string>  $sectionViews
     */
    public function registerBladeTheme(
        ThemeDefinitionData $definition,
        string $layoutView,
        array $sectionViews = [],
        ?string $viewNamespace = null,
        array $viewPaths = [],
        bool $failLoudly = true,
    ): void {
        if ($viewNamespace !== null && $viewNamespace !== '' && $viewPaths !== []) {
            View::addNamespace($viewNamespace, $viewPaths);
        }

        $sectionRenderers = collect($sectionViews)
            ->map(fn (string $view, string $sectionKey): ViewSectionRenderer => new ViewSectionRenderer(
                themeKey: $definition->key,
                sectionKey: $sectionKey,
                view: $view,
                failLoudly: $failLoudly,
            ))
            ->all();

        $this->registry->register(
            definition: $definition,
            themeRenderer: new BladeThemeRenderer($definition->key, $layoutView, $sectionRenderers),
            sectionRenderers: array_values($sectionRenderers),
        );
    }
}
