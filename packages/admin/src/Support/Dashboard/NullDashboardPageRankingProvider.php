<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardPageRankingProvider;
use Capell\Admin\Data\Dashboard\DashboardPageRankingSourceData;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class NullDashboardPageRankingProvider implements DashboardPageRankingProvider
{
    #[Override]
    public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData
    {
        return new DashboardPageRankingSourceData(false, false, false, '', '', '', []);
    }
}
