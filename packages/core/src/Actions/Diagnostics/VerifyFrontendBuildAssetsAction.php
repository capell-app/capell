<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Diagnostics;

use Capell\Core\Data\Diagnostics\FrontendBuildAssetVerificationResultData;
use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Collection<int, FrontendBuildAssetVerificationResultData> run()
 */
final class VerifyFrontendBuildAssetsAction
{
    use AsObject;

    /**
     * @return Collection<int, FrontendBuildAssetVerificationResultData>
     */
    public function handle(): Collection
    {
        return CapellCore::getVendorAssetsForType(VendorAssetEnum::BuildAsset)
            ->map(fn (VendorAssetData $asset): FrontendBuildAssetVerificationResultData => $this->verify($asset))
            ->values();
    }

    private function verify(VendorAssetData $asset): FrontendBuildAssetVerificationResultData
    {
        $buildPath = trim($asset->path(), '/');
        $sourceFile = (string) $asset->file();
        $manifestPath = public_path($buildPath . '/manifest.json');

        if ($sourceFile === '') {
            return new FrontendBuildAssetVerificationResultData(
                asset: $asset,
                buildPath: $buildPath,
                sourceFile: $sourceFile,
                passed: false,
                message: 'Registered build asset does not declare a manifest source file.',
                manifestPath: $manifestPath,
                remediation: 'Register this runtime asset with VendorAssetData::buildAsset($buildPath, $sourceFile).',
            );
        }

        if (! is_file($manifestPath)) {
            return new FrontendBuildAssetVerificationResultData(
                asset: $asset,
                buildPath: $buildPath,
                sourceFile: $sourceFile,
                passed: false,
                message: sprintf('Missing build manifest: public/%s/manifest.json', $buildPath),
                manifestPath: $manifestPath,
                remediation: 'Publish the package build assets or rerun the package setup command.',
            );
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)) {
            return new FrontendBuildAssetVerificationResultData(
                asset: $asset,
                buildPath: $buildPath,
                sourceFile: $sourceFile,
                passed: false,
                message: sprintf('Build manifest is not valid JSON: public/%s/manifest.json', $buildPath),
                manifestPath: $manifestPath,
                remediation: 'Rebuild and publish the package runtime assets.',
            );
        }

        $entry = $manifest[$sourceFile] ?? null;
        if (! is_array($entry) || ! isset($entry['file']) || ! is_string($entry['file']) || $entry['file'] === '') {
            return new FrontendBuildAssetVerificationResultData(
                asset: $asset,
                buildPath: $buildPath,
                sourceFile: $sourceFile,
                passed: false,
                message: sprintf('Build manifest does not contain a file entry for %s.', $sourceFile),
                manifestPath: $manifestPath,
                remediation: 'Rebuild the package runtime assets and publish the updated manifest.',
            );
        }

        $compiledFilePath = public_path($buildPath . '/' . ltrim($entry['file'], '/'));
        if (! is_file($compiledFilePath)) {
            return new FrontendBuildAssetVerificationResultData(
                asset: $asset,
                buildPath: $buildPath,
                sourceFile: $sourceFile,
                passed: false,
                message: sprintf('Compiled asset is missing: public/%s/%s', $buildPath, ltrim($entry['file'], '/')),
                manifestPath: $manifestPath,
                compiledFilePath: $compiledFilePath,
                remediation: 'Publish the package hashed asset files alongside manifest.json.',
            );
        }

        return new FrontendBuildAssetVerificationResultData(
            asset: $asset,
            buildPath: $buildPath,
            sourceFile: $sourceFile,
            passed: true,
            message: sprintf('Build asset is available for %s.', $sourceFile),
            manifestPath: $manifestPath,
            compiledFilePath: $compiledFilePath,
        );
    }
}
