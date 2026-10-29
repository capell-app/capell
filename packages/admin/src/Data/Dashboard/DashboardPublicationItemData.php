<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardPublicationItemData extends Data
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly string $key,
        public readonly int $pageId,
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $title,
        public readonly string $scheduledAt,
        public readonly string $timezone,
        public readonly string $readiness,
        public readonly array $reasons,
        public readonly ?string $url,
        public readonly ?string $readinessLabel = null,
    ) {}
}
