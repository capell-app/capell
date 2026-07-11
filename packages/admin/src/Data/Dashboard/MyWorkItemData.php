<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class MyWorkItemData extends Data
{
    public function __construct(
        public readonly int $pageId,
        public readonly string $title,
        public readonly string $kind,
        public readonly ?string $editUrl,
        public readonly ?string $scheduledAt,
        public readonly ?string $updatedAt,
    ) {}
}
