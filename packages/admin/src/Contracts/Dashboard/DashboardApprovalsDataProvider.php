<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardApprovalsData;
use Illuminate\Contracts\Auth\Authenticatable;

/** @internal */
interface DashboardApprovalsDataProvider
{
    /**
     * Scope every query, item, total and URL to the actor and selected site.
     * Items represent direct assignments, not all approvals the actor may review.
     * A zero limit is a capability/permission probe and must avoid queue/history queries.
     */
    /** @param list<int> $retainedPageIds Authorised rows to retain beyond the new-item preview limit. */
    public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardApprovalsData;
}
