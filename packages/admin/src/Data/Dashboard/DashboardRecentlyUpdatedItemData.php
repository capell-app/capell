<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardRecentlyUpdatedItemData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly string $siteName,
        public readonly string $editor,
        public readonly ?string $updatedAt,
        public readonly ?string $editUrl,
    ) {}
}
