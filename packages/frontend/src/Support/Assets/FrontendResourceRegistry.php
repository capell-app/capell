<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Assets;

use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\Assets\FrontendResourceGroupData;
use Capell\Frontend\Data\Assets\FrontendResourceValidationResultData;
use Capell\Frontend\Data\Assets\ThemeResourceGroupData;

class FrontendResourceRegistry
{
    /** @var array<string, array<string, FrontendResourceData>> */
    private array $groups = [];

    /** @var array<string, array{label: string, description: ?string, package: ?string, origin: string, validation: FrontendResourceValidationResultData}> */
    private array $metadata = [];

    public function group(string $key): FrontendResourceGroupBuilder
    {
        return new FrontendResourceGroupBuilder($this, $key);
    }

    /**
     * @param  array<int, mixed>  $assets
     */
    public function register(
        string $key,
        string $label,
        array $assets,
        ?string $description = null,
        ?string $package = null,
        ?string $defaultBuildPath = null,
    ): void {
        $group = ThemeResourceGroupData::fromDefinition($key, [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'package' => $package,
            'assets' => $assets,
        ], $defaultBuildPath);

        if (! $group instanceof ThemeResourceGroupData) {
            $this->metadata[$key] = [
                'label' => $label,
                'description' => $description,
                'package' => $package,
                'origin' => 'package',
                'validation' => FrontendResourceValidationResultData::invalid(['Resource group does not contain any valid assets.']),
            ];

            return;
        }

        $this->metadata[$key] = [
            'label' => $label,
            'description' => $description,
            'package' => $package,
            'origin' => 'package',
            'validation' => $group->validation,
        ];

        foreach ($group->toFrontendResourceGroup('package')->resources as $resource) {
            $this->add($key, $resource);
        }
    }

    public function add(string $groupKey, FrontendResourceData $resource): void
    {
        $this->groups[$groupKey][$this->resourceKey($resource)] = $resource;
    }

    public function has(string $key): bool
    {
        return isset($this->groups[$key]);
    }

    public function get(string $key): ?FrontendResourceGroupData
    {
        if (! isset($this->groups[$key])) {
            return null;
        }

        return $this->makeGroup($key, array_values($this->groups[$key]));
    }

    /**
     * @return array<string, FrontendResourceGroupData>
     */
    public function all(): array
    {
        return collect($this->groups)
            ->map(fn (array $resources, string $key): FrontendResourceGroupData => $this->makeGroup($key, array_values($resources)))
            ->all();
    }

    /**
     * @param  array<int, FrontendResourceData>  $resources
     */
    private function makeGroup(string $key, array $resources): FrontendResourceGroupData
    {
        $metadata = $this->metadata[$key] ?? null;

        return new FrontendResourceGroupData(
            key: $key,
            resources: $resources,
            label: $metadata['label'] ?? null,
            description: $metadata['description'] ?? null,
            package: $metadata['package'] ?? null,
            origin: $metadata['origin'] ?? 'registry',
            validation: $metadata['validation'] ?? FrontendResourceValidationResultData::valid(),
        );
    }

    private function resourceKey(FrontendResourceData $resource): string
    {
        return implode(':', [
            $resource->kind,
            $resource->buildPath ?? '',
            $resource->source,
            $resource->loadingStrategy->value,
        ]);
    }
}
