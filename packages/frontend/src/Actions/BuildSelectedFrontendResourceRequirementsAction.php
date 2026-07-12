<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetRequirementData;
use Capell\Frontend\Support\Assets\ThemeResourceResolver;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildSelectedFrontendResourceRequirementsAction
{
    use AsObject;

    public function __construct(
        private readonly ThemeResourceResolver $resolver,
    ) {}

    /**
     * @return array<int, FrontendAssetRequirementData>
     */
    public function handle(FrontendAssetContextData $context): array
    {
        $groups = $this->resolver->groups($context->theme);

        return collect($context->widgetResourceUsages)
            ->filter(fn (mixed $usage): bool => is_array($usage) || is_object($usage))
            ->flatMap(function (mixed $usage) use ($groups): array {
                $groupKey = data_get($usage, 'resourceGroup');

                if (! is_string($groupKey) || ! isset($groups[$groupKey])) {
                    return [];
                }

                $publicId = data_get($usage, 'publicId');
                $loadingStrategy = data_get($usage, 'loadingStrategy', data_get($usage, 'presentation.loadingStrategy'));

                return collect($groups[$groupKey]->resources)
                    ->map(fn (FrontendResourceData $resource): FrontendAssetRequirementData => $this->requirement(
                        groupKey: $groupKey,
                        resource: $resource,
                        publicId: is_string($publicId) && $publicId !== '' ? $publicId : null,
                        loadingStrategy: $loadingStrategy instanceof PresentationLoadingStrategy || is_string($loadingStrategy)
                            ? $loadingStrategy
                            : null,
                    ))
                    ->all();
            })
            ->values()
            ->all();
    }

    private function requirement(
        string $groupKey,
        FrontendResourceData $resource,
        ?string $publicId,
        PresentationLoadingStrategy|string|null $loadingStrategy,
    ): FrontendAssetRequirementData {
        $strategy = match (true) {
            $loadingStrategy instanceof PresentationLoadingStrategy => $loadingStrategy,
            is_string($loadingStrategy) => PresentationLoadingStrategy::tryFrom($loadingStrategy) ?? $resource->loadingStrategy,
            default => $resource->loadingStrategy,
        };

        return new FrontendAssetRequirementData(
            handle: 'selected-resource:' . hash('xxh128', implode(':', [
                $groupKey,
                $resource->handle,
                $publicId ?? '',
                $strategy->value,
            ])),
            kind: $resource->kind,
            source: $resource->source,
            buildPath: $resource->buildPath,
            defer: $resource->defer,
            async: $resource->async,
            condition: $strategy === PresentationLoadingStrategy::Eager ? null : $publicId,
            loadingStrategy: $strategy,
            module: $resource->module,
        );
    }
}
