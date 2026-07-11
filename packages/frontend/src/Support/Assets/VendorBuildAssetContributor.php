<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Assets\VendorAssetConditionRegistry;
use Capell\Frontend\Contracts\FrontendAssetContributor;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Illuminate\Support\Str;

final class VendorBuildAssetContributor implements FrontendAssetContributor
{
    public function __construct(
        private readonly VendorAssetConditionRegistry $conditions,
    ) {}

    public function requirements(FrontendAssetContextData $context): array
    {
        return CapellCore::getVendorAssetsForType(VendorAssetEnum::BuildAsset)
            ->filter(fn (VendorAssetData $asset): bool => $this->shouldInclude($asset, $context))
            ->map(fn (VendorAssetData $asset): FrontendAssetRequirementData => new FrontendAssetRequirementData(
                handle: 'vendor-build:' . hash('xxh128', $asset->path() . ':' . $asset->file()),
                kind: Str::endsWith($asset->file(), '.js')
                    ? FrontendAssetRequirementData::KIND_JS
                    : FrontendAssetRequirementData::KIND_CSS,
                source: $asset->file(),
                buildPath: $asset->path(),
                condition: $asset->condition(),
            ))
            ->values()
            ->all();
    }

    private function shouldInclude(VendorAssetData $asset, FrontendAssetContextData $context): bool
    {
        if (! $this->conditions->passes($asset->condition(), $context)) {
            return false;
        }

        if (config('capell-frontend.asset_build_tool') === 'vite'
            && ! file_exists(public_path('hot'))
            && ! file_exists(public_path(trim($asset->path(), '/') . '/manifest.json'))) {
            return false;
        }

        if (! Str::endsWith($asset->file(), '.js')) {
            return true;
        }

        return $context->runtime->usesLivewire
            || $context->runtime->usesAlpine
            || $context->runtime->usesIslands
            || $context->runtime->usesInertia;
    }
}
