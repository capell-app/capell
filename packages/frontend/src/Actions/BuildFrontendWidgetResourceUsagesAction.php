<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\LayoutBuilder\Contracts\Assets\LayoutWidgetResourceUsageContributor;
use Capell\LayoutBuilder\Data\Assets\LayoutWidgetResourceUsageData;
use Illuminate\Contracts\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildFrontendWidgetResourceUsagesAction
{
    use AsObject;

    public function __construct(
        private readonly Application $application,
    ) {}

    /**
     * @return array<int, mixed>
     */
    public function handle(FrontendRenderContextData $context): array
    {
        $usages = BuildSelectedFrontendWidgetResourceUsagesAction::run($context);

        if (! interface_exists(LayoutWidgetResourceUsageContributor::class)) {
            return $usages;
        }

        return collect($this->application->tagged(LayoutWidgetResourceUsageContributor::TAG))
            ->filter(fn (mixed $contributor): bool => $contributor instanceof LayoutWidgetResourceUsageContributor)
            ->flatMap(fn (LayoutWidgetResourceUsageContributor $contributor): array => $contributor->usages($context))
            ->filter(fn (mixed $usage): bool => $usage instanceof LayoutWidgetResourceUsageData)
            ->merge($usages)
            ->unique(function (mixed $usage): string {
                $loadingStrategy = data_get($usage, 'loadingStrategy')
                    ?? data_get($usage, 'presentation.loadingStrategy');

                return implode(':', [
                    data_get($usage, 'widgetKey', ''),
                    data_get($usage, 'resourceGroup', ''),
                    data_get($usage, 'publicId', ''),
                    $loadingStrategy instanceof PresentationLoadingStrategy
                        ? $loadingStrategy->value
                        : (is_string($loadingStrategy) ? $loadingStrategy : ''),
                ]);
            })
            ->values()
            ->all();
    }
}
