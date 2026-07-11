<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

class ThemeResourceGroupData extends Data
{
    /**
     * @param  array<int, ThemeResourceAssetData>  $assets
     */
    public function __construct(
        public string $key,
        public array $assets,
        public ?string $label = null,
        public ?string $description = null,
        public ?string $package = null,
        public string $origin = 'theme',
        public FrontendResourceValidationResultData $validation = new FrontendResourceValidationResultData,
    ) {}

    public static function fromDefinition(string|int $key, mixed $definition, ?string $defaultBuildPath): ?self
    {
        if (! is_array($definition)) {
            return null;
        }

        $groupKey = is_string($definition['key'] ?? null) ? $definition['key'] : (is_string($key) ? $key : null);
        if (! is_string($groupKey) || $groupKey === '') {
            return null;
        }

        $assets = $definition['assets'] ?? $definition;

        if (! is_array($assets)) {
            return null;
        }

        $warnings = [];
        $invalidDefinitions = 0;
        $resourceAssets = collect($assets)
            ->map(function (mixed $asset) use ($defaultBuildPath, &$invalidDefinitions): ?ThemeResourceAssetData {
                $resource = ThemeResourceAssetData::fromDefinition($asset, $defaultBuildPath);

                if (! $resource instanceof ThemeResourceAssetData) {
                    $invalidDefinitions++;
                }

                return $resource;
            })
            ->filter(fn (?ThemeResourceAssetData $asset): bool => $asset instanceof ThemeResourceAssetData)
            ->unique(fn (ThemeResourceAssetData $asset): string => implode(':', [
                $asset->kind,
                $asset->buildPath ?? '',
                $asset->source,
                $asset->loadingStrategy->value,
            ]))
            ->values()
            ->all();

        if ($resourceAssets === []) {
            return null;
        }

        if ($invalidDefinitions > 0) {
            $warnings[] = sprintf('%d invalid resource definition(s) were ignored.', $invalidDefinitions);
        }

        return new self(
            key: $groupKey,
            assets: $resourceAssets,
            label: is_string($definition['label'] ?? null) ? $definition['label'] : null,
            description: is_string($definition['description'] ?? null) ? $definition['description'] : null,
            package: is_string($definition['package'] ?? null) ? $definition['package'] : null,
            validation: new FrontendResourceValidationResultData(valid: true, warnings: $warnings),
        );
    }

    public function toFrontendResourceGroup(?string $origin = null): FrontendResourceGroupData
    {
        return new FrontendResourceGroupData(
            key: $this->key,
            resources: collect($this->assets)
                ->map(fn (ThemeResourceAssetData $asset): FrontendResourceData => $asset->toFrontendResource($this->key))
                ->values()
                ->all(),
            label: $this->label,
            description: $this->description,
            package: $this->package,
            origin: $origin ?? $this->origin,
            validation: $this->validation,
        );
    }
}
