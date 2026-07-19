<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendComponentContributionData;

/**
 * Contributes named components to the frontend Blade and Livewire runtimes.
 *
 * Bind implementations in the service container and tag them with TAG.
 */
interface FrontendComponentContributor
{
    public const string TAG = 'capell.frontend.component-contributor';

    /** @return list<FrontendComponentContributionData> */
    public function components(): array;
}
