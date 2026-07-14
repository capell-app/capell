<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

final class ThemeResourceGroupData extends Data
{
    /** @param  array<int, ThemeResourceAssetData>  $assets */
    public function __construct(
        public readonly string $key,
        public readonly array $assets,
        public readonly string $label,
    ) {}

    public static function fromDefinition(string|int $key, mixed $definition, ?string $defaultBuildPath = null): ?self
    {
        if (! is_array($definition)) {
            return null;
        }

        $groupKey = is_string($definition['key'] ?? null) ? $definition['key'] : (is_string($key) ? $key : null);
        $assets = $definition['assets'] ?? $definition;

        if (! is_string($groupKey) || $groupKey === '' || ! is_array($assets)) {
            return null;
        }

        $typed = collect($assets)
            ->map(static fn (mixed $asset): ?ThemeResourceAssetData => ThemeResourceAssetData::fromDefinition($asset))
            ->filter()
            ->unique(static fn (ThemeResourceAssetData $asset): string => $asset->path . ':' . $asset->loadingStrategy->value)
            ->values()
            ->all();

        if ($typed === []) {
            return null;
        }

        return new self(
            key: $groupKey,
            assets: $typed,
            label: is_string($definition['label'] ?? null) && $definition['label'] !== '' ? $definition['label'] : $groupKey,
        );
    }

    public function toFrontendResourceGroup(?string $origin = null): FrontendResourceGroupData
    {
        return new FrontendResourceGroupData(
            key: $this->key,
            label: $this->label,
            package: 'capell-app/theme-metadata',
            resources: array_map(fn (ThemeResourceAssetData $asset): FrontendResourceData => $asset->toFrontendResource($this->key), $this->assets),
        );
    }
}
