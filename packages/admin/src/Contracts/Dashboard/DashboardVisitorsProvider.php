<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardVisitorsData;
use Illuminate\Contracts\Auth\Authenticatable;

interface DashboardVisitorsProvider
{
    /**
     * Return an actor/site/language-authorised measure for complete comparable
     * periods, with source-zone freshness and explicit availability. Stale figures
     * must be a last-successful snapshot with its last update time.
     */
    public function build(Authenticatable $actor, int $siteId, ?string $language, int $days): DashboardVisitorsData;
}
