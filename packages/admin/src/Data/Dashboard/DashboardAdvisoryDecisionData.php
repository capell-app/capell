<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Dashboard;

use Spatie\LaravelData\Data;

final class DashboardAdvisoryDecisionData extends Data
{
    public function __construct(
        public readonly string $fingerprint,
        public readonly ?string $until = null,
    ) {}
}
