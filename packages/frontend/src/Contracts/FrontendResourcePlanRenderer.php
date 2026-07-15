<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;
use Capell\Frontend\Data\FrontendResourceContextData;

interface FrontendResourcePlanRenderer
{
    public function render(
        FrontendResourcePlanData $plan,
        FrontendResourceContextData $context,
    ): RenderedFrontendResourcesData;
}
