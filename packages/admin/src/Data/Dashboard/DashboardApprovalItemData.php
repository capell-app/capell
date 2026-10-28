<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardApprovalItemData extends Data
{
    public function __construct(
        public readonly int $pageId,
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $title,
        public readonly string $submitter,
        public readonly ?string $submittedAt,
        public readonly ?string $deadline,
        public readonly string $reviewUrl,
        public readonly ?string $timezone = null,
    ) {}
}
