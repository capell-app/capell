<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Packages;

use Capell\Core\Data\ExtensionInstallImpactData;
use Capell\Core\Data\ExtensionSurfaceData;
use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\Manifest\ExtensionHealthCheckData;
use Capell\Core\Data\Manifest\ExtensionScreenshotData;
use Capell\Core\Data\PackageCapabilityGraphData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Support\Manifest\CapellManifestData;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static ExtensionInstallImpactData run(CapellManifestData $manifest, PackageCapabilityGraphData $graph)
 */
final class BuildExtensionInstallImpactAction
{
    use AsObject;

    public function handle(CapellManifestData $manifest, PackageCapabilityGraphData $graph): ExtensionInstallImpactData
    {
        $unknownCapabilities = $graph->unknownFor($manifest->name);

        return new ExtensionInstallImpactData(
            packageName: $manifest->name,
            displayName: $manifest->displayName,
            productGroup: $manifest->productGroup,
            tier: $manifest->tier,
            bundle: $manifest->bundle,
            surfaces: $this->surfaces($manifest),
            surfaceDetails: $this->surfaceDetails($manifest),
            requiredPackages: $manifest->requires,
            supportingPackages: $manifest->supports,
            conflictingPackages: $manifest->conflicts,
            contributionTypes: $this->contributionTypes($manifest),
            contributionClasses: $this->contributionClasses($manifest),
            migrationImpact: $this->migrationImpact($manifest),
            commandImpact: $this->mapEnabledKeys($manifest->commands),
            actionImpact: $this->mapEnabledKeys($manifest->actions),
            settings: $manifest->settings,
            permissions: $manifest->permissions,
            capabilities: $this->capabilities($manifest, $graph),
            unknownCapabilities: $unknownCapabilities,
            cacheImpact: $this->cacheImpact($manifest, $graph),
            publicOutputImpact: $this->publicOutputImpact($manifest),
            healthChecks: $this->healthChecks($manifest),
            scheduledJobs: $this->scheduledJobs($manifest),
            compatibility: $manifest->capellApiVersion,
            supportPolicy: $manifest->commercial->supportPolicy ?? 'unspecified',
            certification: $manifest->commercial->requestedCertification ?? 'unspecified',
            commercialTier: $manifest->commercial->proposedLicense ?? 'unspecified',
            screenshots: $this->screenshots($manifest),
            warnings: $this->warnings($manifest, $unknownCapabilities),
        );
    }

    /** @return list<string> */
    private function surfaces(CapellManifestData $manifest): array
    {
        return array_values(collect($manifest->surfaces)
            ->map(static fn (string $surface): string => strtolower(trim($surface)))
            ->filter(static fn (string $surface): bool => $surface !== '')
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<ExtensionSurfaceData> */
    private function surfaceDetails(CapellManifestData $manifest): array
    {
        return array_map(
            ExtensionSurfaceData::fromSurface(...),
            $this->surfaces($manifest),
        );
    }

    /** @return list<string> */
    private function contributionTypes(CapellManifestData $manifest): array
    {
        return array_values(collect($manifest->contributes)
            ->map(fn (ExtensionContributionData $contribution): string => $contribution->type->value)
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function contributionClasses(CapellManifestData $manifest): array
    {
        return array_values(collect($manifest->contributes)
            ->map(fn (ExtensionContributionData $contribution): ?string => $contribution->class)
            ->filter(static fn (mixed $class): bool => is_string($class))
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function migrationImpact(CapellManifestData $manifest): array
    {
        $impact = [];

        if (($manifest->database['migrations'] ?? false) === true) {
            $impact[] = 'migrations';
        }

        if (($manifest->database['settings'] ?? false) === true) {
            $impact[] = 'settings-migrations';
        }

        $requiredTables = $manifest->database['requiredTables'] ?? [];

        if (is_array($requiredTables)) {
            foreach ($requiredTables as $requiredTable) {
                if (is_string($requiredTable) && $requiredTable !== '') {
                    $impact[] = 'required-table:' . $requiredTable;
                }
            }
        }

        return $impact;
    }

    /**
     * @param  array<string, mixed>  $items
     * @return list<string>
     */
    private function mapEnabledKeys(array $items): array
    {
        $enabled = [];

        foreach ($items as $key => $value) {
            if ($value === null) {
                continue;
            }

            if ($value === false) {
                continue;
            }

            if ($value === []) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $enabled[] = (string) $key;
        }

        return $enabled;
    }

    /** @return list<string> */
    private function capabilities(CapellManifestData $manifest, PackageCapabilityGraphData $graph): array
    {
        return array_values(collect([
            ...$manifest->capabilities,
            ...array_map(
                static fn (PackageCapability $capability): string => $capability->value,
                $graph->capabilitiesFor($manifest->name),
            ),
        ])
            ->unique()
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function cacheImpact(CapellManifestData $manifest, PackageCapabilityGraphData $graph): array
    {
        $impact = [];

        if ($graph->packageHas($manifest->name, PackageCapability::CacheBlocking)) {
            $impact[] = PackageCapability::CacheBlocking->value;
        }

        if ($graph->packageHas($manifest->name, PackageCapability::PublicStatic)) {
            $impact[] = PackageCapability::PublicStatic->value;
        }

        foreach ($manifest->performance->cacheTags as $cacheTag) {
            $impact[] = 'cache-tag:' . $cacheTag;
        }

        if ($manifest->performance->cacheSafety->queueInvalidation) {
            $impact[] = 'queued-invalidation';
        }

        return array_values(array_unique($impact));
    }

    private function publicOutputImpact(CapellManifestData $manifest): string
    {
        if ($this->hasFrontendContribution($manifest)) {
            return 'renders public frontend output';
        }

        if (in_array('frontend', $this->surfaces($manifest), true)) {
            return 'declares frontend runtime';
        }

        return 'no public output declared';
    }

    private function hasFrontendContribution(CapellManifestData $manifest): bool
    {
        return collect($manifest->contributes)->contains(function (ExtensionContributionData $contribution) use ($manifest): bool {
            if ($contribution->type === ExtensionContributionType::ContentWidget
                || ($contribution->metadata['surface'] ?? null) === 'frontend') {
                return true;
            }

            return in_array('frontend', $this->surfaces($manifest), true)
                && in_array($contribution->type, [
                    ExtensionContributionType::Asset,
                    ExtensionContributionType::FrontendComponent,
                    ExtensionContributionType::RenderHook,
                    ExtensionContributionType::Route,
                    ExtensionContributionType::Section,
                ], true);
        });
    }

    /** @return list<string> */
    private function healthChecks(CapellManifestData $manifest): array
    {
        return array_values(array_map(
            static fn (ExtensionHealthCheckData $healthCheck): string => $healthCheck->key,
            $manifest->healthChecks,
        ));
    }

    /** @return list<string> */
    private function scheduledJobs(CapellManifestData $manifest): array
    {
        return array_values(collect($manifest->contributes)
            ->filter(fn (ExtensionContributionData $contribution): bool => $contribution->type === ExtensionContributionType::ScheduledJob)
            ->map(fn (ExtensionContributionData $contribution): ?string => $contribution->class)
            ->filter(static fn (mixed $class): bool => is_string($class))
            ->values()
            ->all());
    }

    /** @return list<string> */
    private function screenshots(CapellManifestData $manifest): array
    {
        return array_values(array_map(
            static fn (ExtensionScreenshotData $screenshot): string => $screenshot->path,
            $manifest->marketplaceScreenshots,
        ));
    }

    /**
     * @param  list<string>  $unknownCapabilities
     * @return list<string>
     */
    private function warnings(CapellManifestData $manifest, array $unknownCapabilities): array
    {
        if ($unknownCapabilities === []) {
            return [];
        }

        return [
            sprintf(
                '%s declares unknown capability [%s].',
                $manifest->name,
                implode(', ', $unknownCapabilities),
            ),
        ];
    }
}
