<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardWorkData;
use Illuminate\Contracts\Auth\Authenticatable;

/** @internal */
interface DashboardWorkDataProvider
{
    /**
     * Scope queries, items, totals and URLs to the actor, site and language.
     * Include personal assignments and requested changes, never approvals.
     */
    /** @param list<int> $retainedPageIds Authorised rows to retain beyond the new-item preview limit. */
    public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null, array $retainedPageIds = []): DashboardWorkData;
}
