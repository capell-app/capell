<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Spatie\LaravelData\Data;

final class ThemeEditorContextData extends Data
{
    public function __construct(
        public ?Theme $theme,
        public ?ThemeDefinitionData $definition,
        public string $themeKey,
        public string $themeName,
    ) {}

    public static function forTheme(Theme $theme, ?ThemeDefinitionData $definition = null): self
    {
        return new self(
            theme: $theme,
            definition: $definition,
            themeKey: $theme->key,
            themeName: $definition?->name ?: $theme->name,
        );
    }
}
