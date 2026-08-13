<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Actions;

use Capell\Core\Facades\CapellCore;
use Capell\Core\ThemeStudio\Assets\ThemeAssetKey;
use Capell\Core\ThemeStudio\Assets\ThemeTokenStore;
use Capell\Core\ThemeStudio\Assets\ThemeTokenValidator;
use Capell\Core\ThemeStudio\Data\BrandProfileData;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeOverrideData;
use Capell\Core\ThemeStudio\Data\ThemePresetData;
use Capell\Core\ThemeStudio\Data\ThemeRuntimeData;
use Capell\Core\ThemeStudio\Exceptions\ThemePresetNotFoundException;
use Capell\Core\ThemeStudio\Preview\ThemePreviewContext;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class ResolveThemeRuntimeAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, array<string, mixed>>  $themeOverrides
     */
    public function handle(
        string $activeTheme,
        string $activePreset,
        BrandProfileData $brand,
        array $themeOverrides = [],
        ?ThemePreviewContext $previewContext = null,
    ): ThemeRuntimeData {
        $registry = resolve(ThemeRegistry::class);
        $previewContext ??= resolve(ThemePreviewContext::class);

        $themeKey = $previewContext->themeKey ?? $activeTheme;
        $presetKey = $previewContext->presetKey ?? $activePreset;
        $definition = $registry->definition($themeKey);
        $preset = $definition->preset($presetKey);

        if ($preset === null) {
            if ($previewContext->presetKey !== null) {
                throw ThemePresetNotFoundException::forKey($themeKey, $presetKey);
            }

            $preset = $definition->presets[0] ?? throw ThemePresetNotFoundException::forKey($themeKey, $presetKey);
            $presetKey = $preset->key;
        }

        $resolvedBrand = $this->resolveLayeredBrand(
            brand: $brand,
            definition: $definition,
            registry: $registry,
            activePresetKey: $presetKey,
            themeOverrides: $themeOverrides,
        );
        $tokenStore = resolve(ThemeTokenStore::class);
        $tokenIssues = $tokenStore->issues($resolvedBrand);
        $tokenCssPath = null;

        try {
            $tokenCssPath = $tokenStore->put(
                $themeKey,
                $presetKey,
                $tokenIssues === [] ? $resolvedBrand : $this->contrastSafeBrand($resolvedBrand),
            );
        } catch (Throwable $throwable) {
            report($throwable);
        }

        return new ThemeRuntimeData(
            themeKey: $themeKey,
            presetKey: $presetKey,
            definition: $definition,
            preset: $preset,
            brand: $resolvedBrand,
            assetKey: ThemeAssetKey::make($themeKey, $presetKey, $resolvedBrand),
            previewing: $previewContext->previewing,
            tokenCssPath: $tokenCssPath,
            tokenIssues: $tokenIssues,
        );
    }

    private function contrastSafeBrand(BrandProfileData $brand): BrandProfileData
    {
        $validator = new ThemeTokenValidator;
        $surfaceColor = $this->validSurfaceColor($brand->surfaceColor, $validator);

        return $brand->merge([
            'surfaceColor' => $surfaceColor,
            'foregroundColor' => $this->contrastSafeColor($brand->foregroundColor, $surfaceColor, $brand, $validator),
            'primaryColor' => $this->contrastSafeColor($brand->primaryColor, $surfaceColor, $brand, $validator),
            'accentColor' => $this->contrastSafeColor($brand->accentColor, $surfaceColor, $brand, $validator),
            'neutralColor' => $this->contrastSafeColor($brand->neutralColor, $surfaceColor, $brand, $validator),
        ]);
    }

    private function validSurfaceColor(string $surfaceColor, ThemeTokenValidator $validator): string
    {
        foreach (['#000000', '#ffffff'] as $contrastColor) {
            if ($validator->contrastIssues($contrastColor, $surfaceColor) === []) {
                return $surfaceColor;
            }
        }

        return (new BrandProfileData)->surfaceColor;
    }

    private function contrastSafeColor(
        string $color,
        string $surfaceColor,
        BrandProfileData $brand,
        ThemeTokenValidator $validator,
    ): string {
        foreach (array_unique([
            $color,
            $brand->foregroundColor,
            '#111827',
            '#f8fafc',
            '#000000',
            '#ffffff',
        ]) as $candidate) {
            if ($validator->contrastIssues($candidate, $surfaceColor) === []) {
                return $candidate;
            }
        }

        return '#000000';
    }

    /**
     * @param  array<string, array<string, mixed>>  $themeOverrides
     */
    private function resolveLayeredBrand(
        BrandProfileData $brand,
        ThemeDefinitionData $definition,
        ThemeRegistry $registry,
        string $activePresetKey,
        array $themeOverrides,
    ): BrandProfileData {
        $definitions = array_reverse($this->definitionChain($definition, $registry));
        $defaultValues = [];
        $overrideValues = [];

        foreach ($definitions as $layerDefinition) {
            $preset = $this->presetForLayer($layerDefinition, $activePresetKey, $definition->key);

            if ($preset instanceof ThemePresetData) {
                $defaultValues = [
                    ...$defaultValues,
                    ...$preset->values,
                ];
            }
        }

        foreach ($definitions as $layerDefinition) {
            $overrideValues = [
                ...$overrideValues,
                ...($themeOverrides[$layerDefinition->key] ?? []),
            ];
        }

        return ResolveBrandProfileAction::run(
            brand: $brand,
            definition: $definition,
            override: new ThemeOverrideData(
                themeKey: $definition->key,
                values: [...$defaultValues, ...$overrideValues],
            ),
        );
    }

    /**
     * @param  list<string>  $visitedThemeKeys
     * @return array<int, ThemeDefinitionData>
     */
    private function definitionChain(ThemeDefinitionData $definition, ThemeRegistry $registry, array $visitedThemeKeys = []): array
    {
        if (in_array($definition->key, $visitedThemeKeys, true)) {
            return [$definition];
        }

        $visitedThemeKeys[] = $definition->key;
        $chain = [$definition];

        if (! CapellCore::hasPackage($definition->package)) {
            return $chain;
        }

        $extendsPackage = CapellCore::getPackage($definition->package)->getExtendsPackage();

        if ($extendsPackage === null || ! CapellCore::hasPackage($extendsPackage)) {
            return $chain;
        }

        $parentThemeKey = CapellCore::getPackage($extendsPackage)->getThemeKey();

        if ($parentThemeKey === null || ! $registry->has($parentThemeKey)) {
            return $chain;
        }

        return [
            ...$chain,
            ...$this->definitionChain($registry->definition($parentThemeKey), $registry, $visitedThemeKeys),
        ];
    }

    private function presetForLayer(
        ThemeDefinitionData $definition,
        string $activePresetKey,
        string $activeThemeKey,
    ): ?ThemePresetData {
        if ($definition->key === $activeThemeKey) {
            return $definition->preset($activePresetKey);
        }

        return $definition->preset($activePresetKey) ?? $definition->presets[0] ?? null;
    }
}
