<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\FrontendResourceContributor;
use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Illuminate\Contracts\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CollectFrontendResourceContributionsAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Application $application) {}

    /** @return array<int, FrontendResourceContributionData> */
    public function handle(FrontendResourceContextData $context, array $widgetResourceUsages = []): array
    {
        return collect($this->application->tagged(FrontendResourceContributor::TAG))
            ->filter(static fn (mixed $contributor): bool => $contributor instanceof FrontendResourceContributor)
            ->flatMap(static fn (FrontendResourceContributor $contributor): array => $contributor->resources($context))
            ->merge(BuildSelectedFrontendResourceContributionsAction::run($context, $widgetResourceUsages))
            ->filter(static fn (mixed $contribution): bool => $contribution instanceof FrontendResourceContributionData)
            ->values()
            ->all();
    }
}
