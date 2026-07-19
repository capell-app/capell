<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\Assets\FrontendWidgetResourceUsageData;
use Capell\Frontend\Data\FrontendRenderContextData;

/**
 * Contributes widget resource usages for the current public render.
 *
 * Implementations should be resolved per request and tagged with TAG.
 */
interface FrontendWidgetResourceUsageContributor
{
    public const string TAG = 'capell.frontend.widget-resource-usage-contributor';

    /** @return list<FrontendWidgetResourceUsageData> */
    public function usages(FrontendRenderContextData $context): array;
}
