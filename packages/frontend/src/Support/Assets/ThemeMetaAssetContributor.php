<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\FrontendAssetContributor;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Support\View\PublicModelMeta;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class ThemeMetaAssetContributor implements FrontendAssetContributor
{
    public function requirements(FrontendAssetContextData $context): array
    {
        $theme = $context->theme;

        if (! $theme instanceof Theme) {
            return [];
        }

        $editorAssets = data_get($theme->meta, 'editor.assets.paths');
        $editorBuildPath = data_get($theme->meta, 'editor.assets.buildPath');
        $hasEditorAssets = is_array($editorAssets);

        $configuredBuildPath = $hasEditorAssets
            ? $editorBuildPath
            : PublicModelMeta::get($theme, 'assets_path');
        $buildPath = is_string($configuredBuildPath) && $configuredBuildPath !== ''
            ? $configuredBuildPath
            : 'build';
        $assets = $hasEditorAssets ? $editorAssets : Arr::wrap(PublicModelMeta::get($theme, 'assets'));

        return collect($assets)
            ->filter(fn (mixed $asset): bool => is_string($asset) && $asset !== '')
            ->filter(fn (string $asset): bool => $this->shouldInclude($asset, $context))
            ->map(fn (string $asset): FrontendAssetRequirementData => new FrontendAssetRequirementData(
                handle: 'theme-meta:' . hash('xxh128', $this->buildPathForAsset($asset, $buildPath) . ':' . $asset),
                kind: Str::endsWith($asset, '.js')
                    ? FrontendAssetRequirementData::KIND_JS
                    : FrontendAssetRequirementData::KIND_CSS,
                source: $asset,
                buildPath: $this->buildPathForAsset($asset, $buildPath),
            ))
            ->values()
            ->all();
    }

    private function shouldInclude(string $asset, FrontendAssetContextData $context): bool
    {
        if (Str::startsWith($asset, ['vendor/', '/vendor/']) && ! is_file(public_path(ltrim($asset, '/')))) {
            return false;
        }

        if (! Str::endsWith($asset, '.js')) {
            return true;
        }

        return $context->runtime->usesLivewire
            || $context->runtime->usesAlpine
            || $context->runtime->usesIslands;
    }

    private function buildPathForAsset(string $asset, string $defaultBuildPath): ?string
    {
        if (Str::startsWith($asset, ['vendor/', '/vendor/'])) {
            return null;
        }

        return $defaultBuildPath;
    }
}
