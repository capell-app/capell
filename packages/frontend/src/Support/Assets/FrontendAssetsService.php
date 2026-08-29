<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use BackedEnum;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Frontend\Contracts\AssetsRegistryInterface;
use Capell\Frontend\Data\FrontendAssetData;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FrontendAssetsService implements AssetsRegistryInterface
{
    /** @var array<string, FrontendAssetData> */
    protected array $assets = [];

    public function registerAsset(AssetEnum|BackedEnum $asset, FrontendAssetData $frontendAsset): static
    {
        $this->assets[$asset->name] = $frontendAsset;
        $this->receipts()?->recordContribution(
            ExtensionContributionType::Asset,
            'frontend-asset:' . $asset->name,
            $frontendAsset::class,
            self::class,
            'frontend',
        );

        return $this;
    }

    /** @return Collection<string, FrontendAssetData> */
    public function getAssets(): Collection
    {
        return collect($this->assets);
    }

    public function getAsset(string|AssetEnum|BackedEnum $name): FrontendAssetData
    {
        if ($name instanceof BackedEnum) {
            $name = $name->name;
        }

        $name = ucfirst($name);

        throw_unless(isset($this->assets[$name]), InvalidArgumentException::class, sprintf("Asset with name '%s' does not exist.", $name));

        return $this->assets[$name];
    }

    public function hasAsset(string $name): bool
    {
        return isset($this->assets[$name]);
    }

    private function receipts(): ?RecordsExtensionContributionReceipt
    {
        return app()->bound(RecordsExtensionContributionReceipt::class)
            ? resolve(RecordsExtensionContributionReceipt::class)
            : null;
    }
}
