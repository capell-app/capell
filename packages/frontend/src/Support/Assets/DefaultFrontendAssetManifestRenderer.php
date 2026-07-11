<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Frontend\Contracts\FrontendAssetManifestRenderer;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Illuminate\Foundation\Mix;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class DefaultFrontendAssetManifestRenderer implements FrontendAssetManifestRenderer
{
    public function __construct(
        private readonly Vite $vite,
        private readonly Mix $mix,
        private readonly UrlGenerator $url,
    ) {}

    public function render(FrontendAssetManifestData $manifest, ?FrontendAssetContextData $context = null): HtmlString
    {
        $html = [];

        foreach ($manifest->buildAssetsByPath() as $buildPath => $buildAssets) {
            $html[] = $this->renderBuildAssets($buildAssets, $buildPath);
        }

        foreach ($manifest->css as $assetRequirement) {
            if (! $assetRequirement instanceof FrontendAssetRequirementData) {
                continue;
            }

            if ($assetRequirement->isBuildAsset()) {
                continue;
            }

            $html[] = sprintf(
                '<link href="%s" rel="stylesheet" />',
                e(asset(ltrim($assetRequirement->source, '/'))),
            );
        }

        return new HtmlString(implode(PHP_EOL, array_filter($html, static fn (string $tag): bool => $tag !== '')));
    }

    /**
     * @param  array<int, string>  $buildAssets
     */
    private function renderBuildAssets(array $buildAssets, ?string $buildPath): string
    {
        $buildAssets = Arr::wrap($buildAssets);
        $buildTool = config('capell-frontend.asset_build_tool');

        if ($buildAssets === []) {
            return '';
        }

        if ($buildTool === 'vite') {
            return $this->renderViteBuildAssets($buildAssets, $buildPath);
        }

        if ($buildTool === 'mix') {
            return collect($buildAssets)
                ->map(fn (string $buildAsset): string => $this->renderMixAsset($buildAsset, $buildPath))
                ->filter()
                ->implode('');
        }

        return collect($buildAssets)
            ->map(fn (string $buildAsset): string => $this->renderPublicBuildAsset($buildAsset, $buildPath))
            ->filter()
            ->implode('');
    }

    /**
     * @param  array<int, string>  $buildAssets
     */
    private function renderViteBuildAssets(array $buildAssets, ?string $buildPath): string
    {
        if ($this->vite->isRunningHot()) {
            return (string) ($this->vite)($buildAssets, $buildPath);
        }

        $manifest = $this->viteManifest($buildPath);

        if ($manifest === null) {
            return collect($buildAssets)
                ->map(fn (string $buildAsset): string => $this->renderPublicBuildAsset($buildAsset, $buildPath))
                ->filter()
                ->implode('');
        }

        $availableAssets = collect($buildAssets)
            ->filter(fn (string $buildAsset): bool => array_key_exists($buildAsset, $manifest))
            ->values()
            ->all();
        $missingAssets = collect($buildAssets)
            ->reject(fn (string $buildAsset): bool => array_key_exists($buildAsset, $manifest))
            ->values()
            ->all();

        return collect([
            $availableAssets === [] ? '' : (string) ($this->vite)($availableAssets, $buildPath),
            collect($missingAssets)
                ->map(fn (string $buildAsset): string => $this->renderPublicBuildAsset($buildAsset, $buildPath))
                ->filter()
                ->implode(''),
        ])
            ->filter()
            ->implode('');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function viteManifest(?string $buildPath): ?array
    {
        $buildDirectory = trim($buildPath ?? 'build', '/');
        $manifestPath = public_path($buildDirectory . '/manifest.json');

        if (! is_file($manifestPath)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), associative: true);

        return is_array($manifest) ? $manifest : null;
    }

    private function renderMixAsset(string $buildAsset, ?string $buildPath): string
    {
        if (Str::endsWith($buildAsset, '.css')) {
            return sprintf('<link rel="stylesheet" href="%s">', e((string) ($this->mix)($buildAsset, $buildPath)));
        }

        if (Str::endsWith($buildAsset, '.js')) {
            return sprintf('<script src="%s"></script>', e((string) ($this->mix)($buildAsset, $buildPath)));
        }

        return '';
    }

    private function renderPublicBuildAsset(string $buildAsset, ?string $buildPath): string
    {
        $path = trim(trim((string) $buildPath, '/') . '/' . ltrim($buildAsset, '/'), '/');
        $url = e($this->url->asset($path));

        if (Str::endsWith($buildAsset, '.css')) {
            return sprintf('<link rel="stylesheet" href="%s">', $url);
        }

        if (Str::endsWith($buildAsset, '.js')) {
            return sprintf('<script src="%s"></script>', $url);
        }

        return '';
    }
}
