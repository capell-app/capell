<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Illuminate\Foundation\Vite;
use Spatie\LaravelData\Data;
use Throwable;

class FrontendAssetManifestData extends Data
{
    /**
     * @param  array<int, FrontendAssetRequirementData>  $css
     * @param  array<int, FrontendAssetRequirementData>  $js
     * @param  array<int, FrontendAssetRequirementData>  $inline
     * @param  array<int, FrontendAssetRequirementData>  $preloads
     * @param  array<int, FrontendAssetRequirementData>  $lazy
     * @param  array<int, FrontendAssetRequirementData>  $critical
     * @param  array<int, FrontendAssetRequirementData>  $rawRequirements
     */
    public function __construct(
        public array $css,
        public array $js,
        public array $inline,
        public array $preloads,
        public FrontendRuntimeManifestData $runtime,
        public array $lazy = [],
        public array $critical = [],
        public array $rawRequirements = [],
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public function buildAssetsByPath(): array
    {
        /** @var array<string, array<int, string>> $assetsByPath */
        $assetsByPath = [];

        foreach ([...$this->css, ...$this->js] as $requirement) {
            if (! $requirement instanceof FrontendAssetRequirementData) {
                continue;
            }

            if (! $requirement->isBuildAsset()) {
                continue;
            }

            $buildPath = $requirement->buildPath;

            if ($buildPath === null) {
                continue;
            }

            $assetsByPath[$buildPath] ??= [];
            $assetsByPath[$buildPath][] = $requirement->source;
        }

        return array_map(
            fn (array $assets): array => array_values(array_unique($assets)),
            $assetsByPath,
        );
    }

    public function hasJavaScript(): bool
    {
        return $this->js !== [] || $this->inline !== [] || collect($this->lazy)->contains(fn (FrontendAssetRequirementData $asset): bool => $asset->isJavaScript());
    }

    /**
     * @return array<string, array<int, array{kind: string, url: string, loading: string, defer: bool, async: bool, module: bool}>>
     */
    public function lazyAssetsByPublicId(): array
    {
        $assets = [];

        foreach ([...$this->rawRequirements, ...$this->lazy] as $requirement) {
            if (! $requirement instanceof FrontendAssetRequirementData) {
                continue;
            }

            if ($requirement->condition === null) {
                continue;
            }

            $assets[$requirement->condition] ??= [];
            $asset = [
                'kind' => $requirement->kind,
                'url' => $this->resolvedAssetUrl($requirement),
                'loading' => $requirement->loadingStrategy->value,
                'defer' => $requirement->defer,
                'async' => $requirement->async,
                'module' => $requirement->isJavaScript() && $requirement->usesModuleScript(),
            ];

            $key = $asset['kind'] . ':' . $asset['url'];
            $assets[$requirement->condition][$key] = $asset;
        }

        return array_map(
            static fn (array $publicAssets): array => array_values($publicAssets),
            $assets,
        );
    }

    public function resolvedAssetUrl(FrontendAssetRequirementData $requirement): string
    {
        if ($requirement->isBuildAsset() && config('capell-frontend.asset_build_tool') === 'vite') {
            try {
                return resolve(Vite::class)->asset($requirement->source, $requirement->buildPath);
            } catch (Throwable) {
                // Build manifests may be absent in isolated package tests; fall back to the public path.
            }
        }

        $path = trim(($requirement->buildPath !== null ? trim($requirement->buildPath, '/') . '/' : '') . ltrim($requirement->source, '/'), '/');

        return asset($path);
    }
}
