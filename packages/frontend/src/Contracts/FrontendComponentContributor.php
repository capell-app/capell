<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendComponentContributionData;

interface FrontendComponentContributor
{
    public const string TAG = 'capell.frontend.component-contributor';

    /** @return list<FrontendComponentContributionData> */
    public function components(): array;
}
