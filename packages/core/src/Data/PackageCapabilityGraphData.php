<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\PackageCapability;
use Override;
use Spatie\LaravelData\Data;

final class PackageCapabilityGraphData extends Data
{
    /**
     * @param  list<PackageCapabilityNodeData>  $nodes
     * @param  array<string, list<string>>  $unknownCapabilities
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $unknownCapabilities,
    ) {}

    public function packageHas(string $packageName, PackageCapability $capability): bool
    {
        return array_any($this->nodes, fn (PackageCapabilityNodeData $node): bool => $node->packageName === $packageName && $node->capability === $capability);
    }

    /**
     * @return list<PackageCapability>
     */
    public function capabilitiesFor(string $packageName): array
    {
        return array_values(collect($this->nodes)
            ->filter(fn (PackageCapabilityNodeData $node): bool => $node->packageName === $packageName)
            ->map(fn (PackageCapabilityNodeData $node): PackageCapability => $node->capability)
            ->unique(fn (PackageCapability $capability): string => $capability->value)
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    public function packagesWith(PackageCapability $capability): array
    {
        return array_values(collect($this->nodes)
            ->filter(fn (PackageCapabilityNodeData $node): bool => $node->capability === $capability)
            ->map(fn (PackageCapabilityNodeData $node): string => $node->packageName)
            ->unique()
            ->values()
            ->all());
    }

    /**
     * @return list<string>
     */
    public function unknownFor(string $packageName): array
    {
        return $this->unknownCapabilities[$packageName] ?? [];
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'nodes' => array_map(
                static fn (PackageCapabilityNodeData $node): array => $node->toArray(),
                $this->nodes,
            ),
            'unknownCapabilities' => $this->unknownCapabilities,
        ];
    }
}
