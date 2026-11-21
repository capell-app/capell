<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Data\Rendering\FrontendResourceContextData;
use Capell\Frontend\Data\Assets\FrontendResourcePlanData;
use Capell\Frontend\Data\Assets\RenderedFrontendResourcesData;

interface FrontendResourcePlanRenderer
{
    public function render(
        FrontendResourcePlanData $plan,
        FrontendResourceContextData $context,
    ): RenderedFrontendResourcesData;
}
