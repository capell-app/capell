<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\SiteStatsData;

/**
 * Optional extension of SiteStatsDataProvider for providers that honour the
 * dashboard's site selection and its rolling analytics window. A provider that
 * only implements SiteStatsDataProvider keeps receiving build($period) and the
 * dashboard says that the site filter does not apply to its counts.
 */
interface ScopedSiteStatsDataProvider extends SiteStatsDataProvider
{
    /**
     * @param  int|null  $siteId  The site chosen on the dashboard; null means every site the person can access.
     */
    public function buildFor(string $period, ?int $siteId): SiteStatsData;
}
