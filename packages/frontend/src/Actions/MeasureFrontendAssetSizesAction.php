<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\Assets\FrontendResourceAssetSizeData;
use Capell\Frontend\Data\Assets\FrontendResourceSizeReportData;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsObject;

class MeasureFrontendAssetSizesAction
{
    use AsObject;

    public function __construct(
        private readonly Filesystem $files,
    ) {}

    public function handle(FrontendAssetManifestData $manifest): FrontendResourceSizeReportData
    {
        $assets = collect([...$manifest->css, ...$manifest->js])
            ->map(fn (FrontendAssetRequirementData $asset): FrontendResourceAssetSizeData => $this->measure($asset))
            ->values()
            ->all();

        return new FrontendResourceSizeReportData(
            rawCssBytes: $this->sum($assets, FrontendAssetRequirementData::KIND_CSS, 'rawBytes'),
            gzipCssBytes: $this->sum($assets, FrontendAssetRequirementData::KIND_CSS, 'gzipBytes'),
            rawJsBytes: $this->sum($assets, FrontendAssetRequirementData::KIND_JS, 'rawBytes'),
            gzipJsBytes: $this->sum($assets, FrontendAssetRequirementData::KIND_JS, 'gzipBytes'),
            assets: $assets,
            warnings: collect($assets)->flatMap(fn (FrontendResourceAssetSizeData $asset): array => $asset->warnings)->values()->all(),
        );
    }

    private function measure(FrontendAssetRequirementData $asset): FrontendResourceAssetSizeData
    {
        if (! $asset->isBuildAsset()) {
            return new FrontendResourceAssetSizeData(
                handle: $asset->handle,
                kind: $asset->kind,
                source: $asset->source,
                buildPath: $asset->buildPath,
                rawBytes: null,
                gzipBytes: null,
                measurable: false,
                warnings: [sprintf('Asset %s is not a local build asset.', $asset->source)],
            );
        }

        $path = $this->path($asset);

        if (! $this->files->isFile($path)) {
            return new FrontendResourceAssetSizeData(
                handle: $asset->handle,
                kind: $asset->kind,
                source: $asset->source,
                buildPath: $asset->buildPath,
                rawBytes: null,
                gzipBytes: null,
                measurable: false,
                warnings: [sprintf('Asset %s could not be measured at %s.', $asset->source, $path)],
            );
        }

        try {
            $contents = $this->files->get($path);
        } catch (FileNotFoundException) {
            $contents = '';
        }

        return new FrontendResourceAssetSizeData(
            handle: $asset->handle,
            kind: $asset->kind,
            source: $asset->source,
            buildPath: $asset->buildPath,
            rawBytes: strlen($contents),
            gzipBytes: strlen((string) gzencode($contents, 9)),
            measurable: true,
        );
    }

    private function path(FrontendAssetRequirementData $asset): string
    {
        $relativePath = trim(trim((string) $asset->buildPath, '/') . '/' . ltrim($asset->source, '/'), '/');

        return public_path($relativePath);
    }

    /**
     * @param  array<int, FrontendResourceAssetSizeData>  $assets
     */
    private function sum(array $assets, string $kind, string $property): int
    {
        return collect($assets)
            ->filter(fn (FrontendResourceAssetSizeData $asset): bool => $asset->kind === $kind)
            ->sum(fn (FrontendResourceAssetSizeData $asset): int => (int) $asset->{$property});
    }
}
