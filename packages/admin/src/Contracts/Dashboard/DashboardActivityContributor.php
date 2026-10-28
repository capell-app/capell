<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardActivityItemData;
use Illuminate\Contracts\Auth\Authenticatable;

interface DashboardActivityContributor
{
    public const string TAG = 'capell.admin.dashboard-activity';

    /** @return list<DashboardActivityItemData> Permitted meaningful events, most recent first. */
    public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $limit): array;
}
