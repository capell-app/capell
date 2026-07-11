<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Themes;

use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Spatie\LaravelData\Data;

final class ThemeLibraryCardData extends Data
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $bestFit
     * @param  list<string>  $includedSections
     * @param  list<string>  $presetNames
     */
    public function __construct(
        public string $section,
        public string $title,
        public string $themeKey,
        public string $description,
        public ?string $imageUrl,
        public ?int $themeId,
        public bool $installed,
        public bool $active,
        public bool $enabled,
        public int $siteCount,
        public string $package,
        public array $tags,
        public array $bestFit,
        public array $includedSections,
        public array $presetNames,
        public bool $demoReady,
        public ThemeDiagnosticsData $diagnostics,
    ) {}

    public static function forInstalledTheme(
        Theme $theme,
        ?ThemeDefinitionData $definition,
        ThemeDiagnosticsData $diagnostics,
        ?string $imageUrl,
    ): self {
        $admin = is_array($theme->admin) ? $theme->admin : [];
        $description = self::stringValue(
            data_get($admin, 'editor.description'),
            $definition?->description ?: (string) __('capell-admin::table.theme_no_description'),
        );

        return new self(
            section: 'installed',
            title: $theme->name,
            themeKey: $theme->key,
            description: $description,
            imageUrl: $imageUrl,
            themeId: (int) $theme->getKey(),
            installed: true,
            active: $theme->isDefault(),
            enabled: $theme->status,
            siteCount: $theme->sites_count ?? $theme->sites()->count(),
            package: self::stringValue($admin['package'] ?? null, $definition?->package ?: $theme->key),
            tags: array_values($definition?->tags ?: self::stringList($admin['tags'] ?? [])),
            bestFit: array_values($definition?->bestFit ?: self::stringList($admin['bestFit'] ?? $admin['best_fit'] ?? [])),
            includedSections: array_values($definition?->includedSections ?: self::stringList($admin['includedSections'] ?? $admin['included_sections'] ?? [])),
            presetNames: $definition instanceof ThemeDefinitionData ? array_values($definition->presetOptions()) : [],
            demoReady: (bool) ($admin['demoReady'] ?? $admin['demo_ready'] ?? false),
            diagnostics: $diagnostics,
        );
    }

    public static function forAvailableDefinition(ThemeDefinitionData $definition, ThemeDiagnosticsData $diagnostics): self
    {
        return new self(
            section: 'available',
            title: $definition->name,
            themeKey: $definition->key,
            description: $definition->description,
            imageUrl: $definition->previewImage,
            themeId: null,
            installed: false,
            active: false,
            enabled: false,
            siteCount: 0,
            package: $definition->package,
            tags: array_values($definition->tags),
            bestFit: array_values($definition->bestFit),
            includedSections: array_values($definition->includedSections),
            presetNames: array_values($definition->presetOptions()),
            demoReady: false,
            diagnostics: $diagnostics,
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(fn (string $item): string => trim($item))
            ->values()
            ->all());
    }

    private static function stringValue(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }
}
