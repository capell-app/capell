<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionInstallImpactData extends Data
{
    /**
     * @param  list<string>  $surfaces
     * @param  list<ExtensionSurfaceData>  $surfaceDetails
     * @param  list<string>  $requiredPackages
     * @param  list<string>  $supportingPackages
     * @param  list<string>  $conflictingPackages
     * @param  list<string>  $contributionTypes
     * @param  list<string>  $contributionClasses
     * @param  list<string>  $migrationImpact
     * @param  list<string>  $commandImpact
     * @param  list<string>  $actionImpact
     * @param  list<string>  $settings
     * @param  list<string>  $permissions
     * @param  list<string>  $capabilities
     * @param  list<string>  $unknownCapabilities
     * @param  list<string>  $cacheImpact
     * @param  list<string>  $healthChecks
     * @param  list<string>  $scheduledJobs
     * @param  list<string>  $screenshots
     * @param  list<string>  $warnings
     * @param  list<ExtensionInstallImpactNodeData>  $dependencyNodes
     */
    public function __construct(
        public readonly string $packageName,
        public readonly string $displayName,
        public readonly string $productGroup,
        public readonly string $tier,
        public readonly ?string $bundle,
        public readonly array $surfaces,
        public readonly array $surfaceDetails,
        public readonly array $requiredPackages,
        public readonly array $supportingPackages,
        public readonly array $conflictingPackages,
        public readonly array $contributionTypes,
        public readonly array $contributionClasses,
        public readonly array $migrationImpact,
        public readonly array $commandImpact,
        public readonly array $actionImpact,
        public readonly array $settings,
        public readonly array $permissions,
        public readonly array $capabilities,
        public readonly array $unknownCapabilities,
        public readonly array $cacheImpact,
        public readonly string $publicOutputImpact,
        public readonly array $healthChecks,
        public readonly array $scheduledJobs,
        public readonly string $compatibility,
        public readonly string $supportPolicy,
        public readonly string $certification,
        public readonly string $commercialTier,
        public readonly array $screenshots,
        public readonly array $warnings,
        public readonly array $dependencyNodes = [],
    ) {}

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'packageName' => $this->packageName,
            'displayName' => $this->displayName,
            'productGroup' => $this->productGroup,
            'tier' => $this->tier,
            'bundle' => $this->bundle,
            'surfaces' => $this->surfaces,
            'surfaceDetails' => array_map(
                static fn (ExtensionSurfaceData $surface): array => $surface->toArray(),
                $this->surfaceDetails,
            ),
            'requiredPackages' => $this->requiredPackages,
            'supportingPackages' => $this->supportingPackages,
            'conflictingPackages' => $this->conflictingPackages,
            'contributionTypes' => $this->contributionTypes,
            'contributionClasses' => $this->contributionClasses,
            'migrationImpact' => $this->migrationImpact,
            'commandImpact' => $this->commandImpact,
            'actionImpact' => $this->actionImpact,
            'settings' => $this->settings,
            'permissions' => $this->permissions,
            'capabilities' => $this->capabilities,
            'unknownCapabilities' => $this->unknownCapabilities,
            'cacheImpact' => $this->cacheImpact,
            'publicOutputImpact' => $this->publicOutputImpact,
            'healthChecks' => $this->healthChecks,
            'scheduledJobs' => $this->scheduledJobs,
            'compatibility' => $this->compatibility,
            'supportPolicy' => $this->supportPolicy,
            'certification' => $this->certification,
            'commercialTier' => $this->commercialTier,
            'screenshots' => $this->screenshots,
            'warnings' => $this->warnings,
            'dependencyNodes' => array_map(
                static fn (ExtensionInstallImpactNodeData $node): array => $node->toArray(),
                $this->dependencyNodes,
            ),
        ];
    }
}
