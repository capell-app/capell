<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Dashboard;

use Capell\Admin\Contracts\Dashboard\DashboardApprovalsDataProvider;
use Capell\Admin\Data\Dashboard\DashboardApprovalsData;
use Illuminate\Contracts\Auth\Authenticatable;
use Override;

final class NullDashboardApprovalsDataProvider implements DashboardApprovalsDataProvider
{
    #[Override]
    public function build(Authenticatable $actor, ?int $siteId, int $limit, ?string $language = null): DashboardApprovalsData
    {
        return new DashboardApprovalsData(
            available: false,
            canReview: false,
            hasReviewed: false,
            assignedReviewCount: 0,
            teamReviewCount: 0,
            teamReviewUrl: null,
            items: [],
        );
    }
}
