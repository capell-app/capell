<?php

declare(strict_types=1);

namespace Capell\Admin\Concerns;

use BackedEnum;
use Capell\Admin\Data\AdminAssetData;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Core\Enums\AssetEnum;
use Illuminate\Support\Collection;
use InvalidArgumentException;

trait HasAdminAssets
{
    /**
     * @var array<string, AdminAssetData>
     */
    protected array $assets = [];

    public function registerAsset(AssetEnum|BackedEnum $asset, AdminAssetData $adminAsset): static
    {
        $this->assets[$asset->name] = $adminAsset;

        return $this;
    }

    /**
     * @return Collection<string, AdminAssetData>
     */
    public function getAssets(): Collection
    {
        $this->activateAdminRuntime();

        return collect($this->assets);
    }

    public function getAsset(string|AssetEnum|BackedEnum $name): AdminAssetData
    {
        $this->activateAdminRuntime();

        if ($name instanceof BackedEnum) {
            $name = $name->name;
        }

        $name = ucfirst($name);

        throw_unless(isset($this->assets[$name]), InvalidArgumentException::class, sprintf("Asset with name '%s' does not exist.", $name));

        return $this->assets[$name];
    }

    public function hasAsset(string $name): bool
    {
        $this->activateAdminRuntime();

        return isset($this->assets[$name]);
    }

    private function activateAdminRuntime(): void
    {
        if (app()->bound(AdminRuntimeActivator::class)) {
            resolve(AdminRuntimeActivator::class)->activate();
        }
    }
}
