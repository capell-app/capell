<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\Assets\FrontendResourceContributionData;
use Capell\Frontend\Data\FrontendResourceContextData;

/**
 * Contributes styles, scripts, or inline resources to a public render plan.
 *
 * Bind implementations in the service container and tag them with TAG.
 * Frontend invokes each contributor for the current render context before
 * dependency ordering, validation, deduplication, and output placement.
 */
interface FrontendResourceContributor
{
    public const string TAG = 'capell.frontend.resource-contributor';

    /**
     * Return resource declarations owned by this contributor.
     *
     * The method must not render output or perform database queries.
     *
     * @return list<FrontendResourceContributionData>
     */
    public function resources(FrontendResourceContextData $context): array;
}
