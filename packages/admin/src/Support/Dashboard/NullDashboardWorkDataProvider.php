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
    /** @param list<int> $retainedPageIds Authorised rows to retain beyond the new-item preview limit. */
    public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardWorkData
    {
        return new DashboardWorkData(available: false, total: 0, items: []);
    }
}
