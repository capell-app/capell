<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\FrontendResourceGroupData;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<FrontendResourceGroupData> */
final class FrontendResourceRegistry extends AbstractKeyedRegistry
{
    /** @var array<string, FrontendResourceData> */
    private array $resources = [];

    public function register(FrontendResourceGroupData $group): void
    {
        if ($this->hasItem($group->key)) {
            throw new InvalidArgumentException(sprintf('Frontend resource group [%s] is already registered.', $group->key));
        }

        foreach ($group->resources as $resource) {
            if (isset($this->resources[$resource->handle])) {
                throw new InvalidArgumentException(sprintf('Frontend resource handle is already registered: [%s].', $resource->handle));
            }
        }

        $this->setItem($group->key, $group);
        $this->receipts()?->recordFromContext(
            ExtensionContributionType::Asset,
            'resource-group:' . $group->key,
            FrontendResourceGroupData::class,
            self::class,
            'frontend',
        );

        foreach ($group->resources as $resource) {
            $this->resources[$resource->handle] = $resource;
        }
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    public function get(string $key): ?FrontendResourceGroupData
    {
        return $this->getItem($key);
    }

    public function resource(string $handle): ?FrontendResourceData
    {
        return $this->resources[$handle] ?? null;
    }

    /** @return array<string, FrontendResourceGroupData> */
    public function all(): array
    {
        return $this->allItems();
    }

    private function receipts(): ?ExtensionContributionReceiptRegistry
    {
        return app()->bound(ExtensionContributionReceiptRegistry::class)
            ? resolve(ExtensionContributionReceiptRegistry::class)
            : null;
    }
}
