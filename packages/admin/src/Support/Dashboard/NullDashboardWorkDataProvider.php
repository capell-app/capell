<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardWorkDataProvider;
use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class NullDashboardWorkDataProvider implements DashboardWorkDataProvider
{
    #[Override]
    public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null): DashboardWorkData
    {
        return new DashboardWorkData(available: false, total: 0, items: []);
    }
}
