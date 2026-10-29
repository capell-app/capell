<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardAttentionRecoveryCandidateData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $fingerprint,
        public readonly int $siteId,
        public readonly ?int $pageId,
        public readonly ?string $language,
        public readonly ?string $pageUuid = null,
    ) {}
}
