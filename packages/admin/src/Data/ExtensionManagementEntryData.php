<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use BackedEnum;
use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ExtensionManagementEntryData extends Data
{
    /**
     * @param  list<array{label: string, url: string, icon: null|string|BackedEnum}>  $secondaryPages
     * @param  list<ExtensionManagementSurfaceData>  $managementSurfaces
     * @param  list<string>  $imageUrls
     * @param  list<array<string, mixed>>  $healthAlerts
     * @param  list<string>  $missingRequiredTables
     */
    public function __construct(
        public readonly string $packageName,
        public readonly string $label,
        public readonly ?string $description,
        public readonly ?string $version,
        public readonly bool $installed,
        public readonly bool $enabled,
        public readonly bool $available,
        public readonly bool $core,
        public readonly bool $canUninstall,
        public readonly ?CarbonImmutable $installedAt,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly ?string $primaryUrl,
        public readonly ?string $externalUrl,
        public readonly ?string $documentationUrl,
        public readonly ?string $authorName,
        public readonly ?string $imageUrl,
        public readonly null|string|BackedEnum $icon,
        public readonly array $imageUrls = [],
        public readonly array $secondaryPages = [],
        public readonly array $managementSurfaces = [],
        public readonly ?string $latestVersion = null,
        public readonly bool $updateAvailable = false,
        public readonly string $tier = 'free',
        public readonly string $certification = 'community',
        public readonly string $runtimeStatus = 'not_installed',
        public readonly bool $runtimeAllowed = true,
        public readonly string $healthState = 'ok',
        public readonly int $contributionCount = 0,
        public readonly array $healthAlerts = [],
        public readonly array $missingRequiredTables = [],
        public readonly bool $premiumMissingMarketplaceAccount = false,
        public readonly bool $blocked = false,
        public readonly bool $needsAttention = false,
        public readonly int $riskScore = 0,
        public readonly string $productGroup = 'Other',
    ) {}

    /**
     * @return array{
     *     __key: string,
     *     id: string,
     *     packageName: string,
     *     label: string,
     *     name: string,
     *     description: ?string,
     *     version: ?string,
     *     installed: bool,
     *     enabled: bool,
     *     available: bool,
     *     core: bool,
     *     canUninstall: bool,
     *     installedAt: ?CarbonImmutable,
     *     updatedAt: ?CarbonImmutable,
     *     primaryUrl: ?string,
     *     externalUrl: ?string,
     *     documentationUrl: ?string,
     *     authorName: ?string,
     *     imageUrl: ?string,
     *     imageUrls: list<string>,
     *     icon: null|string|BackedEnum,
     *     secondaryPages: list<array{label: string, url: string, icon: null|string|BackedEnum}>,
     *     managementSurfaces: list<array{packageName: string, label: string, type: string, icon: null|string|BackedEnum, settingsGroup: ?string}>,
     *     latestVersion: ?string,
     *     updateAvailable: bool,
     *     tier: string,
     *     certification: string,
     *     runtimeStatus: string,
     *     runtimeAllowed: bool,
     *     healthState: string,
     *     contributionCount: int,
     *     healthAlerts: list<array<string, mixed>>,
     *     missingRequiredTables: list<string>,
     *     premiumMissingMarketplaceAccount: bool,
     *     blocked: bool,
     *     needsAttention: bool,
     *     riskScore: int,
     *     productGroup: string
     * }
     */
    public function toTableRecord(): array
    {
        return [
            '__key' => $this->packageName,
            'id' => $this->packageName,
            'packageName' => $this->packageName,
            'label' => $this->label,
            'name' => $this->label,
            'description' => $this->description,
            'version' => $this->version,
            'installed' => $this->installed,
            'enabled' => $this->enabled,
            'available' => $this->available,
            'core' => $this->core,
            'canUninstall' => $this->canUninstall,
            'installedAt' => $this->installedAt,
            'updatedAt' => $this->updatedAt,
            'primaryUrl' => $this->primaryUrl,
            'externalUrl' => $this->externalUrl,
            'documentationUrl' => $this->documentationUrl,
            'authorName' => $this->authorName,
            'imageUrl' => $this->imageUrl,
            'imageUrls' => $this->imageUrls,
            'icon' => $this->icon,
            'secondaryPages' => $this->secondaryPages,
            'managementSurfaces' => array_values(array_map(
                fn (ExtensionManagementSurfaceData $surface): array => $surface->toRecord(),
                $this->managementSurfaces,
            )),
            'latestVersion' => $this->latestVersion,
            'updateAvailable' => $this->updateAvailable,
            'tier' => $this->tier,
            'certification' => $this->certification,
            'runtimeStatus' => $this->runtimeStatus,
            'runtimeAllowed' => $this->runtimeAllowed,
            'healthState' => $this->healthState,
            'contributionCount' => $this->contributionCount,
            'healthAlerts' => $this->healthAlerts,
            'missingRequiredTables' => $this->missingRequiredTables,
            'premiumMissingMarketplaceAccount' => $this->premiumMissingMarketplaceAccount,
            'blocked' => $this->blocked,
            'needsAttention' => $this->needsAttention,
            'riskScore' => $this->riskScore,
            'productGroup' => $this->productGroup,
        ];
    }
}
