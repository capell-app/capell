<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardScopePreferenceData extends Data
{
    public function __construct(
        public readonly ?int $siteId,
        public readonly ?string $language,
    ) {}
}
