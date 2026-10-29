<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardVisitorsProvider;
use Capell\Admin\Data\Dashboard\DashboardVisitorsData;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class NullDashboardVisitorsProvider implements DashboardVisitorsProvider
{
    #[Override]
    public function build(Authenticatable $actor, int $siteId, ?string $language, int $days): DashboardVisitorsData
    {
        return new DashboardVisitorsData('disconnected', '', '', '', null, null, null);
    }
}
