<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardApprovalsData extends Data
{
    /** @param list<DashboardApprovalItemData> $items */
    public function __construct(
        public readonly bool $available,
        public readonly bool $canReview,
        public readonly bool $hasReviewed,
        public readonly int $assignedReviewCount,
        public readonly int $teamReviewCount,
        public readonly ?string $teamReviewUrl,
        public readonly array $items,
    ) {}
}
