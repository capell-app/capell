<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Data\AssetData;
use Capell\Core\Enums\AssetEnum;
use Capell\Core\Enums\ExtensionContributionType;
use Illuminate\Support\Collection;
use InvalidArgumentException;

trait HasAssets
{
    /**
     * @var array<string, AssetData>
     */
    protected array $assets = [];

    public function registerAsset(AssetData $asset): static
    {
        $this->assets[$asset->name] = $asset;
        if (app()->bound(RecordsExtensionContributionReceipt::class)) {
            resolve(RecordsExtensionContributionReceipt::class)->recordContribution(
                ExtensionContributionType::Asset,
                'core-asset:' . $asset->name,
                $asset->model,
                self::class,
            );
        }

        return $this;
    }

    /**
     * @return Collection<string, AssetData>
     */
    public function getAssets(): Collection
    {
        return collect($this->assets);
    }

    public function getAsset(string|AssetEnum $name): AssetData
    {
        if ($name instanceof AssetEnum) {
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
}
