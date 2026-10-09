<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\FrontendResourceSelectionPolicy;
use Capell\Frontend\Data\Assets\FrontendResourceSelectionData;
use Capell\Frontend\Data\FrontendResourceContextData;
use Illuminate\Contracts\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SelectFrontendResourcesAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Application $application) {}

    public function handle(FrontendResourceContextData $context, FrontendResourceSelectionData $selection): FrontendResourceSelectionData
    {
        foreach ($this->application->tagged(FrontendResourceSelectionPolicy::TAG) as $policy) {
            if ($policy instanceof FrontendResourceSelectionPolicy) {
                $selection = $policy->select($context, $selection);
            }
        }

        return $selection;
    }
}
