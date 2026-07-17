<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Packages;

use Capell\Core\Data\Manifest\ExtensionContributionData;
use Capell\Core\Data\PackageCapabilityGraphData;
use Capell\Core\Data\PackageCapabilityNodeData;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Enums\PackageCapability;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Core\Support\PackageRegistry\CapellPackageRegistry;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static PackageCapabilityGraphData run(array<string, CapellManifestData>|null $manifests = null)
 */
final class BuildPackageCapabilityGraphAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, CapellManifestData>|null  $manifests
     */
    public function handle(?array $manifests = null): PackageCapabilityGraphData
    {
        $manifests ??= resolve(CapellPackageRegistry::class)->all();

        $nodes = [];
        $unknownCapabilities = [];

        foreach ($manifests as $manifest) {
            foreach ($manifest->capabilities as $capability) {
                $typedCapability = PackageCapability::tryFrom($capability);

                if (! $typedCapability instanceof PackageCapability) {
                    $unknownCapabilities[$manifest->name][] = $capability;

                    continue;
                }

                $nodes[] = new PackageCapabilityNodeData(
                    packageName: $manifest->name,
                    capability: $typedCapability,
                    source: 'manifest',
                    explicit: true,
                );
            }

            array_push($nodes, ...$this->derivedNodes($manifest, $nodes));
        }

        return new PackageCapabilityGraphData(
            nodes: $this->deduplicateNodes($nodes),
            unknownCapabilities: array_map(
                static fn (array $capabilities): array => array_values(array_unique($capabilities)),
                $unknownCapabilities,
            ),
        );
    }

    /**
     * @param  list<PackageCapabilityNodeData>  $existingNodes
     * @return list<PackageCapabilityNodeData>
     */
    private function derivedNodes(CapellManifestData $manifest, array $existingNodes): array
    {
        $nodes = [];

        if ($this->isFrontendPackage($manifest)) {
            $nodes[] = $this->derived($manifest, PackageCapability::FrontendSurface, 'frontend surface or content contribution');
        }

        if ($this->hasFrontendAssetContribution($manifest)) {
            $nodes[] = $this->derived($manifest, PackageCapability::FrontendAssets, 'frontend asset contribution');
        }

        if ($this->hasRenderHookContribution($manifest)) {
            $nodes[] = $this->derived($manifest, PackageCapability::RenderHook, 'render hook contribution');
        }

        if ($this->hasLivewireContribution($manifest)) {
            $nodes[] = $this->derived($manifest, PackageCapability::RequiresLivewire, 'frontend component contribution');
        }

        if ($this->isFrontendPackage($manifest) && $manifest->performance->requiresLivewire === true) {
            $nodes[] = $this->derived($manifest, PackageCapability::RequiresLivewire, 'frontend performance budget requires Livewire');
        }

        if ($this->isFrontendPackage($manifest) && (
            ! $manifest->performance->cacheSafety->cacheable
            || $manifest->performance->cacheSafety->sensitiveOutput
            || $manifest->performance->cacheabilityProfile === 'uncacheable'
            || $manifest->performance->publicQueryRisk === true
        )) {
            $nodes[] = $this->derived($manifest, PackageCapability::CacheBlocking, 'frontend cache safety metadata blocks public cache');
        }

        if ($this->isFrontendPackage($manifest) && $manifest->performance->cacheSafety->cacheable && ! $manifest->performance->cacheSafety->sensitiveOutput) {
            $nodes[] = $this->derived($manifest, PackageCapability::PublicStatic, 'frontend cache safety metadata allows public static output');
        }

        return array_values(array_filter(
            $nodes,
            fn (PackageCapabilityNodeData $node): bool => ! $this->hasExplicitNode($existingNodes, $manifest->name, $node->capability),
        ));
    }

    private function derived(CapellManifestData $manifest, PackageCapability $capability, string $reason): PackageCapabilityNodeData
    {
        return new PackageCapabilityNodeData(
            packageName: $manifest->name,
            capability: $capability,
            source: 'derived',
            explicit: false,
            reason: $reason,
        );
    }

    private function isFrontendPackage(CapellManifestData $manifest): bool
    {
        return in_array('frontend', $manifest->surfaces, true)
            || collect($manifest->contributes)->contains(
                fn (ExtensionContributionData $contribution): bool => $contribution->type === ExtensionContributionType::ContentWidget
                    || ($contribution->metadata['surface'] ?? null) === 'frontend',
            );
    }

    private function hasFrontendAssetContribution(CapellManifestData $manifest): bool
    {
        return collect($manifest->contributes)->contains(
            fn (ExtensionContributionData $contribution): bool => $contribution->type === ExtensionContributionType::Asset
                && (($contribution->metadata['surface'] ?? null) === 'frontend' || in_array('frontend', $manifest->surfaces, true)),
        );
    }

    private function hasRenderHookContribution(CapellManifestData $manifest): bool
    {
        return collect($manifest->contributes)->contains(
            fn (ExtensionContributionData $contribution): bool => $contribution->type === ExtensionContributionType::RenderHook,
        );
    }

    private function hasLivewireContribution(CapellManifestData $manifest): bool
    {
        if ($manifest->runtime === 'livewire') {
            return true;
        }

        return collect($manifest->contributes)->contains(
            fn (ExtensionContributionData $contribution): bool => $contribution->type === ExtensionContributionType::FrontendComponent,
        );
    }

    /**
     * @param  list<PackageCapabilityNodeData>  $nodes
     */
    private function hasExplicitNode(array $nodes, string $packageName, PackageCapability $capability): bool
    {
        return array_any($nodes, fn (PackageCapabilityNodeData $node): bool => $node->explicit && $node->packageName === $packageName && $node->capability === $capability);
    }

    /**
     * @param  list<PackageCapabilityNodeData>  $nodes
     * @return list<PackageCapabilityNodeData>
     */
    private function deduplicateNodes(array $nodes): array
    {
        $seen = [];
        $deduplicated = [];

        foreach ($nodes as $node) {
            $key = $node->packageName . ':' . $node->capability->value;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $deduplicated[] = $node;
        }

        return $deduplicated;
    }
}
