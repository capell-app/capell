<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\FrontendResourceContextData;

interface FrontendResourceContributor
{
    public const string TAG = 'capell.frontend.resource-contributor';

    /** @return array<int, FrontendResourceContributionData> */
    public function resources(FrontendResourceContextData $context): array;
}
