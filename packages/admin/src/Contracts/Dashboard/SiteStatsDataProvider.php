<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\SiteStatsData;

interface SiteStatsDataProvider
{
    public function build(string $period): SiteStatsData;
}
