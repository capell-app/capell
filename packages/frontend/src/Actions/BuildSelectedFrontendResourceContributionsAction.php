<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Data\Assets\FrontendResourceActivationData;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\Assets\FrontendResourceData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Capell\Frontend\Support\Assets\ThemeResourceResolver;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildSelectedFrontendResourceContributionsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly ThemeResourceResolver $resolver) {}

    /**
     * @param  array<int, mixed>  $widgetResourceUsages
     * @return array<int, FrontendResourceContributionData>
     */
    public function handle(FrontendResourceContextData $context, array $widgetResourceUsages): array
    {
        $groups = $this->resolver->groups($context->theme);

        return collect($widgetResourceUsages)
            ->filter(static fn (mixed $usage): bool => is_array($usage) || is_object($usage))
            ->flatMap(function (mixed $usage) use ($groups): array {
                $groupKey = data_get($usage, 'resourceGroup');

                if (! is_string($groupKey) || ! isset($groups[$groupKey])) {
                    return [];
                }

                $target = data_get($usage, 'publicId');
                $loading = data_get($usage, 'loadingStrategy', data_get($usage, 'presentation.loadingStrategy'));
                $strategy = $loading instanceof PresentationLoadingStrategy
                    ? $loading
                    : (is_string($loading) ? PresentationLoadingStrategy::tryFrom($loading) : null);

                return array_map(
                    static function (FrontendResourceData $resource) use ($strategy, $target): FrontendResourceContributionData {
                        $resolvedStrategy = $strategy ?? $resource->loadingStrategy;
                        $activations = $resolvedStrategy !== PresentationLoadingStrategy::Eager && is_string($target) && $target !== ''
                            ? [new FrontendResourceActivationData($target, $resolvedStrategy)]
                            : [new FrontendResourceActivationData('page', PresentationLoadingStrategy::Eager)];

                        return new FrontendResourceContributionData($resource, $activations);
                    },
                    $groups[$groupKey]->resources,
                );
            })
            ->values()
            ->all();
    }
}
