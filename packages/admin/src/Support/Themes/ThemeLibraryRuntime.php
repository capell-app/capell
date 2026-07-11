<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Themes;

use Capell\Admin\Actions\Themes\ResolveThemeDefinitionsAction;
use Capell\Admin\Actions\Themes\ValidateThemeDefinitionAction;
use Capell\Admin\Data\Themes\ThemeDiagnosticsData;
use Capell\Admin\Data\Themes\ThemeLibraryCardData;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;

final class ThemeLibraryRuntime
{
    /** @var array<string, ThemeDefinitionData>|null */
    private ?array $definitions = null;

    /** @var array<string, ThemeDiagnosticsData> */
    private array $diagnostics = [];

    /** @var array<int, ThemeCardData> */
    private array $themeCards = [];

    /** @var array<int, ThemeLibraryCardData> */
    private array $installedCards = [];

    /**
     * @return array<string, ThemeDefinitionData>
     */
    public function definitions(): array
    {
        return $this->definitions ??= ResolveThemeDefinitionsAction::run();
    }

    public function definition(string $themeKey): ?ThemeDefinitionData
    {
        return $this->definitions()[$themeKey] ?? null;
    }

    public function diagnostics(string $themeKey, ?Theme $theme = null, ?ThemeDefinitionData $definition = null): ThemeDiagnosticsData
    {
        $cacheKey = $theme instanceof Theme
            ? sprintf('theme:%s:%s', $theme->getKey(), $themeKey)
            : sprintf('definition:%s', $themeKey);

        return $this->diagnostics[$cacheKey] ??= ValidateThemeDefinitionAction::run(
            $themeKey,
            $definition ?? $this->definition($themeKey),
            $theme,
        );
    }

    public function themeCard(Theme $theme): ThemeCardData
    {
        return $this->themeCards[(int) $theme->getKey()] ??= ThemeCardData::fromTheme(
            $theme,
            $this->definition($theme->key),
        );
    }

    public function installedCard(Theme $theme): ThemeLibraryCardData
    {
        return $this->installedCards[(int) $theme->getKey()] ??= $this->makeInstalledCard($theme);
    }

    public function availableCard(ThemeDefinitionData $definition): ThemeLibraryCardData
    {
        return ThemeLibraryCardData::forAvailableDefinition(
            definition: $definition,
            diagnostics: $this->diagnostics($definition->key, definition: $definition),
        );
    }

    private function makeInstalledCard(Theme $theme): ThemeLibraryCardData
    {
        $definition = $this->definition($theme->key);
        $card = $this->themeCard($theme);

        return ThemeLibraryCardData::forInstalledTheme(
            theme: $theme,
            definition: $definition,
            diagnostics: $this->diagnostics($theme->key, $theme, $definition),
            imageUrl: $card->imageUrl,
        );
    }
}
