<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Reports;

use Spatie\LaravelData\Data;

final class ReportMetricData extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly int|string $value,
        public readonly ?string $description = null,
    ) {}
}
