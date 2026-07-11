<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Data\VendorAssetData;
use Capell\Core\Enums\VendorAssetEnum;
use Illuminate\Support\Collection;

/**
 * Trait that provides simple registration and retrieval of vendor assets
 * grouped by their VendorAssetEnum type.
 */
trait HasVendorAssets
{
    /**
     * @var array<string, array<int, VendorAssetData>>
     */
    protected array $vendorAssets = [];

    public function registerVendorAsset(VendorAssetData $asset): static
    {
        $this->vendorAssets[$asset->type->value][] = $asset;

        return $this;
    }

    /**
     * Check whether there are any registered assets for the given type.
     */
    public function hasVendorAssets(VendorAssetEnum $type): bool
    {
        return ($this->vendorAssets[$type->value] ?? []) !== [];
    }

    /**
     * Get all assets for a specific vendor asset type.
     *
     * @return Collection<int, VendorAssetData>
     */
    public function getVendorAssetsForType(VendorAssetEnum $type): Collection
    {
        return collect($this->vendorAssets[$type->value] ?? []);
    }

    /**
     * Get the entire map of registered vendor assets grouped by type value.
     *
     * @return Collection<string, array<int, VendorAssetData>>
     */
    public function getAllVendorAssets(): Collection
    {
        return collect($this->vendorAssets);
    }
}
