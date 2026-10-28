<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardApprovalsData;
use Illuminate\Contracts\Auth\Authenticatable;

interface DashboardApprovalsDataProvider
{
    /**
     * Scope every query, item, total and URL to the actor and selected site.
     * Items represent direct assignments, not all approvals the actor may review.
     */
    public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null): DashboardApprovalsData;
}
