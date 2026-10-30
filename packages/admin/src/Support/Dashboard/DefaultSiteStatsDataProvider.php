<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Actions\Dashboard\BuildDefaultSiteStatsAction;
use Capell\Admin\Contracts\Dashboard\ScopedSiteStatsDataProvider;
use Capell\Admin\Data\Dashboard\SiteStatsData;

final class DefaultSiteStatsDataProvider implements ScopedSiteStatsDataProvider
{
    public function build(string $period): SiteStatsData
    {
        return BuildDefaultSiteStatsAction::run($period);
    }

    public function buildFor(string $period, ?int $siteId): SiteStatsData
    {
        return BuildDefaultSiteStatsAction::run($period, $siteId, scoped: true);
    }
}
