<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\SiteAccess;

use Capell\Core\Data\SiteAccessContextData;
use Capell\Frontend\Contracts\SiteAccessExemptionContributor;

final readonly class SiteAccessExemptionRegistry
{
    /**
     * @param  iterable<SiteAccessExemptionContributor>  $contributors
     */
    public function __construct(private iterable $contributors) {}

    public function exempts(SiteAccessContextData $context): bool
    {
        foreach ($this->contributors as $contributor) {
            if ($contributor->exempts($context)) {
                return true;
            }
        }

        return false;
    }
}
