<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardAttentionItemData extends Data
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly string $key,
        public readonly string $fingerprint,
        public readonly bool $urgent,
        public readonly string $title,
        public readonly int $siteId,
        public readonly string $siteName,
        public readonly string $description,
        public readonly array $reasons,
        public readonly ?string $url,
        public readonly ?string $deadline = null,
        public readonly ?int $pageId = null,
    ) {}
}
