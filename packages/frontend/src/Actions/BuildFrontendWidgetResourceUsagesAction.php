<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Contracts\FrontendWidgetResourceUsageContributor;
use Capell\Frontend\Data\Assets\FrontendWidgetResourceUsageData;
use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Contracts\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildFrontendWidgetResourceUsagesAction
{
    use AsFake;
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

        return collect($this->application->tagged(FrontendWidgetResourceUsageContributor::TAG))
            ->filter(fn (mixed $contributor): bool => $contributor instanceof FrontendWidgetResourceUsageContributor)
            ->flatMap(fn (FrontendWidgetResourceUsageContributor $contributor): array => $contributor->usages($context))
            ->filter(fn (mixed $usage): bool => $usage instanceof FrontendWidgetResourceUsageData)
            ->merge($usages)
            ->unique(function (mixed $usage): string {
                $loadingStrategy = data_get($usage, 'loadingStrategy', data_get($usage, 'presentation.loadingStrategy'));

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
