<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Actions\Dashboard\BuildDefaultSiteStatsAction;
use Capell\Admin\Contracts\Dashboard\SiteStatsDataProvider;
use Capell\Admin\Data\Dashboard\SiteStatsData;

final class DefaultSiteStatsDataProvider implements SiteStatsDataProvider
{
    public function build(string $period): SiteStatsData
    {
        return BuildDefaultSiteStatsAction::run($period);
    }
}
