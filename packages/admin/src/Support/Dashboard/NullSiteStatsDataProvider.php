<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\SiteStatsDataProvider;
use Capell\Admin\Data\Dashboard\SiteStatsData;

final class NullSiteStatsDataProvider implements SiteStatsDataProvider
{
    public function build(string $period): SiteStatsData
    {
        return new SiteStatsData(
            workQueueCount: 0,
            publishedCount: 0,
            sparklinePublished: [],
        );
    }
}
