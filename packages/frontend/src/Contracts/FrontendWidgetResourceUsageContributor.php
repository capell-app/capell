<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\Assets\FrontendWidgetResourceUsageData;
use Capell\Frontend\Data\FrontendRenderContextData;

interface FrontendWidgetResourceUsageContributor
{
    public const string TAG = 'capell.frontend.widget-resource-usage-contributor';

    /** @return list<FrontendWidgetResourceUsageData> */
    public function usages(FrontendRenderContextData $context): array;
}
