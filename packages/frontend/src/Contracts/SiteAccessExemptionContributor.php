<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Core\Data\SiteAccessContextData;

interface SiteAccessExemptionContributor
{
    public const string TAG = 'capell.frontend.site_access_exemption';

    public function exempts(SiteAccessContextData $context): bool;
}
