<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\Assets\FrontendResourceGroupData;
use Capell\Frontend\Data\FrontendAssetManifestData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Support\Assets\ThemeResourceResolver;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildFrontendResourceGraphAction
{
    use AsObject;

    public function __construct(
        private readonly ThemeResourceResolver $resolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(FrontendAssetManifestData $manifest, ?FrontendRenderContextData $context = null): array
    {
        $groups = $this->resolver->groups($context?->theme);
        $widgetUsages = $this->widgetUsages($context);

        return [
            'page' => $this->modelSummary($context?->page),
            'layout' => $this->modelSummary($context?->layout),
            'theme' => $this->modelSummary($context?->theme),
            'resourceGroups' => collect($groups)->map(fn ($group): array => [
                'key' => $group->key,
                'label' => $group->label,
                'description' => $group->description,
                'origin' => $group->origin,
                'package' => $group->package,
                'valid' => $group->validation->valid,
                'warnings' => $group->validation->warnings,
                'assets' => collect($group->resources)->map(fn ($asset): array => [
                    'handle' => $asset->handle,
                    'kind' => $asset->kind,
                    'source' => $asset->source,
                    'buildPath' => $asset->buildPath,
                    'loadingStrategy' => $asset->loadingStrategy->value,
                    'reasons' => $this->reasonsForGroup($group->key, $widgetUsages),
                ])->values()->all(),
            ])->values()->all(),
            'assets' => collect([...$manifest->css, ...$manifest->js, ...$manifest->inline, ...$manifest->preloads, ...$manifest->lazy])
                ->map(fn (FrontendAssetRequirementData $asset): array => [
                    'handle' => $asset->handle,
                    'kind' => $asset->kind,
                    'source' => $asset->source,
                    'buildPath' => $asset->buildPath,
                    'condition' => $asset->condition,
                    'loadingStrategy' => $asset->loadingStrategy->value,
                    'reasons' => $this->reasonsForAsset($asset, $groups, $widgetUsages),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function widgetUsages(?FrontendRenderContextData $context): array
    {
        if (! $context instanceof FrontendRenderContextData) {
            return [];
        }

        return collect(BuildFrontendWidgetResourceUsagesAction::run($context))
            ->map(fn (mixed $usage): array => [
                'widgetKey' => data_get($usage, 'widgetKey'),
                'resourceGroup' => data_get($usage, 'resourceGroup'),
                'publicId' => data_get($usage, 'publicId'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $widgetUsages
     * @return array<int, string>
     */
    private function reasonsForGroup(string $groupKey, array $widgetUsages): array
    {
        return collect($widgetUsages)
            ->filter(fn (array $usage): bool => ($usage['resourceGroup'] ?? null) === $groupKey)
            ->map(fn (array $usage): string => sprintf(
                'Widget %s requested resource group %s.',
                $usage['widgetKey'] ?? 'unknown',
                $groupKey,
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, FrontendResourceGroupData>  $groups
     * @param  array<int, array<string, mixed>>  $widgetUsages
     * @return array<int, string>
     */
    private function reasonsForAsset(FrontendAssetRequirementData $asset, array $groups, array $widgetUsages): array
    {
        return collect($groups)
            ->filter(fn ($group): bool => collect($group->resources)->contains(
                fn ($resource): bool => $resource->source === $asset->source && $resource->kind === $asset->kind,
            ))
            ->flatMap(fn ($group): array => $this->reasonsForGroup($group->key, $widgetUsages) ?: [
                sprintf('Resource group %s includes %s.', $group->key, $asset->source),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function modelSummary(?object $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return [
            'type' => $model::class,
            'key' => method_exists($model, 'getKey') ? $model->getKey() : null,
        ];
    }
}
