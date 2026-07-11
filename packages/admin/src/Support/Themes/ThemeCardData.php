<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Themes;

use Capell\Admin\Data\Themes\ThemeCompatibilityData;
use Capell\Core\Models\Theme;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\Data;

final class ThemeCardData extends Data
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<int, string>  $bestFit
     * @param  array<int, string>  $includedSections
     * @param  array<int, string>  $optionalIntegrations
     */
    public function __construct(
        public string $title,
        public string $key,
        public string $description,
        public ?string $imageUrl,
        public bool $isActive,
        public bool $isNew,
        public bool $isEnabled,
        public int $siteCount,
        public ThemeCompatibilityData $compatibility,
        public string $package,
        public array $tags,
        public array $bestFit,
        public array $includedSections,
        public array $optionalIntegrations,
        public bool $demoReady,
    ) {}

    public static function fromTheme(Theme $theme, ?ThemeDefinitionData $definition = null): self
    {
        $admin = is_array($theme->admin) ? $theme->admin : [];
        $definition ??= resolve(ThemeLibraryRuntime::class)->definition($theme->key);
        $description = trim((string) ($admin['description'] ?? ''));

        return new self(
            title: $theme->name,
            key: $theme->key,
            description: self::description($description, $definition),
            imageUrl: self::imageUrl($theme, $definition),
            isActive: $theme->isDefault(),
            isNew: $theme->created_at !== null && $theme->created_at->greaterThan(now()->subDay()),
            isEnabled: $theme->status,
            siteCount: $theme->sites_count ?? $theme->sites()->count(),
            compatibility: ThemeCompatibilityData::fromAdmin($admin),
            package: $definition instanceof ThemeDefinitionData ? $definition->package : self::stringValue($admin['package'] ?? null, $theme->key),
            tags: $definition instanceof ThemeDefinitionData ? $definition->tags : self::stringList($admin['tags'] ?? []),
            bestFit: $definition instanceof ThemeDefinitionData ? $definition->bestFit : self::stringList($admin['bestFit'] ?? $admin['best_fit'] ?? []),
            includedSections: $definition instanceof ThemeDefinitionData ? $definition->includedSections : self::stringList($admin['includedSections'] ?? $admin['included_sections'] ?? []),
            optionalIntegrations: self::stringList($admin['optionalIntegrations'] ?? $admin['optional_integrations'] ?? []),
            demoReady: (bool) ($admin['demoReady'] ?? $admin['demo_ready'] ?? false),
        );
    }

    private static function description(string $adminDescription, ?ThemeDefinitionData $definition): string
    {
        if ($adminDescription !== '') {
            return $adminDescription;
        }

        if ($definition instanceof ThemeDefinitionData && trim($definition->description) !== '') {
            return $definition->description;
        }

        return (string) __('capell-admin::table.theme_no_description');
    }

    private static function imageUrl(Theme $theme, ?ThemeDefinitionData $definition): ?string
    {
        $url = $theme->getFirstMediaUrl('image');

        if ($url !== '') {
            return $url;
        }

        $storedImageUrl = self::storedImageUrl($theme);

        if ($storedImageUrl !== null) {
            return $storedImageUrl;
        }

        return $definition instanceof ThemeDefinitionData && trim($definition->previewImage) !== ''
            ? $definition->previewImage
            : null;
    }

    private static function storedImageUrl(Theme $theme): ?string
    {
        $admin = is_array($theme->admin) ? $theme->admin : [];
        $manualImage = $admin['image'] ?? null;

        if (is_string($manualImage) && $manualImage !== '') {
            return Storage::disk('public')->url($manualImage);
        }

        $generatedImage = $theme->readyGeneratedImage();

        return $generatedImage !== null ? Storage::disk('public')->url($generatedImage) : null;
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->values()
            ->all();
    }

    private static function stringValue(mixed $value, string $fallback): string
    {
        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }
}
