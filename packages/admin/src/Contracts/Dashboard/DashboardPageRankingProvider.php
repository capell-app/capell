<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Dashboard;

use Capell\Admin\Data\Dashboard\DashboardPageRankingSourceData;
use Illuminate\Contracts\Auth\Authenticatable;

/** @internal */
interface DashboardPageRankingProvider
{
    /**
     * Return all eligible managed pages, scoped to the actor, site and language.
     * Exclude utility/error pages and use complete, comparable source-local periods.
     * Report requested and completely covered site counts; never combine incompatible measures.
     * Provide periodStart and periodEnd as source-local YYYY-MM-DD dates, with
     * periodEnd exclusive. Stale figures must include their last update time.
     * isNew must be backed by page creation evidence, not merely zero previous views;
     * an evidenced new page has previous = 0 when the preceding period is complete.
     */
    public function build(Authenticatable $actor, ?int $siteId, ?string $language, int $days): DashboardPageRankingSourceData;
}
