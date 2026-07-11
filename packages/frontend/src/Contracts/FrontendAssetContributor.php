<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendAssetContextData;
use Capell\Frontend\Data\FrontendAssetRequirementData;

interface FrontendAssetContributor
{
    public const string TAG = 'capell.frontend.asset-contributor';

    /**
     * @return array<int, FrontendAssetRequirementData>
     */
    public function requirements(FrontendAssetContextData $context): array;
}
