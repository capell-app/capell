<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Reports;

use Spatie\LaravelData\Data;

final class ReportSnapshotData extends Data
{
    /**
     * @param  list<ReportMetricData>  $metrics
     * @param  list<ReportFindingData>  $findings
     */
    public function __construct(
        public readonly string $key,
        public readonly string $emptyState,
        public readonly array $metrics = [],
        public readonly array $findings = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->metrics === [] && $this->findings === [];
    }
}
