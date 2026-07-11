<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use BackedEnum;
use Capell\Core\Enums\AssetEnum;
use Capell\Frontend\Data\FrontendAssetData;
use Illuminate\Support\Collection;

interface AssetsRegistryInterface
{
    public function registerAsset(AssetEnum|BackedEnum $asset, FrontendAssetData $frontendAsset): static;

    /** @return Collection<string, FrontendAssetData> */
    public function getAssets(): Collection;

    public function getAsset(string|AssetEnum|BackedEnum $name): FrontendAssetData;

    public function hasAsset(string $name): bool;
}
