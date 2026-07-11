<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Extensions;

use ArrayAccess;
use Spatie\LaravelData\Data;

/**
 * @implements ArrayAccess<string, mixed>
 */
final class ExtensionOperationPackageData extends Data implements ArrayAccess
{
    /**
     * @param  list<ExtensionHealthAlertData>  $healthAlerts
     * @param  list<string>  $missingRequiredTables
     * @param  list<array{label: string, url: string, permission: ?string, type: string}>  $managementEntries
     */
    public function __construct(
        public readonly string $packageName,
        public readonly string $label,
        public readonly ?string $description,
        public readonly ?string $version,
        public readonly ?string $latestVersion,
        public readonly ?string $imageUrl,
        /** @var list<string> */
        public readonly array $imageUrls,
        public readonly bool $updateAvailable,
        public readonly bool $installed,
        public readonly bool $enabled,
        public readonly bool $available,
        public readonly bool $core,
        public readonly string $visibility,
        public readonly bool $canUninstall,
        public readonly string $tier,
        public readonly string $certification,
        public readonly string $runtimeStatus,
        public readonly bool $runtimeAllowed,
        public readonly string $healthState,
        public readonly array $healthAlerts,
        public readonly array $missingRequiredTables,
        public readonly int $contributionCount,
        public readonly array $managementEntries,
        public readonly bool $premiumMissingMarketplaceAccount,
        public readonly bool $blocked,
        public readonly bool $needsAttention,
        public readonly int $riskScore,
        public readonly string $productGroup = 'Other',
    ) {}

    /** @return array<string, mixed> */
    public function toRecord(): array
    {
        return [
            'packageName' => $this->packageName,
            'label' => $this->label,
            'description' => $this->description,
            'version' => $this->version,
            'latestVersion' => $this->latestVersion,
            'imageUrl' => $this->imageUrl,
            'imageUrls' => $this->imageUrls,
            'updateAvailable' => $this->updateAvailable,
            'installed' => $this->installed,
            'enabled' => $this->enabled,
            'available' => $this->available,
            'core' => $this->core,
            'visibility' => $this->visibility,
            'canUninstall' => $this->canUninstall,
            'tier' => $this->tier,
            'certification' => $this->certification,
            'runtimeStatus' => $this->runtimeStatus,
            'runtimeAllowed' => $this->runtimeAllowed,
            'healthState' => $this->healthState,
            'healthAlerts' => array_map(
                fn (ExtensionHealthAlertData $alert): array => $alert->toRecord(),
                $this->healthAlerts,
            ),
            'missingRequiredTables' => $this->missingRequiredTables,
            'contributionCount' => $this->contributionCount,
            'managementEntries' => $this->managementEntries,
            'premiumMissingMarketplaceAccount' => $this->premiumMissingMarketplaceAccount,
            'blocked' => $this->blocked,
            'needsAttention' => $this->needsAttention,
            'riskScore' => $this->riskScore,
            'productGroup' => $this->productGroup,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->toRecord());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toRecord()[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        //
    }

    public function offsetUnset(mixed $offset): void
    {
        //
    }
}
