<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardActivityItemData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $title,
        public readonly string $actor,
        public readonly string $event,
        public readonly string $occurredAt,
        public readonly ?string $url,
        public int $changes = 1,
        public readonly ?int $pageId = null,
        public readonly ?string $eventLabel = null,
    ) {}
}
