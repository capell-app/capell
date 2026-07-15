<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Reports;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class ReportSnapshotData extends Data
{
    public readonly CarbonImmutable $generatedAt;

    /**
     * @param  list<ReportMetricData>  $metrics
     * @param  list<ReportFindingData>  $findings
     */
    public function __construct(
        public readonly string $key,
        public readonly string $emptyState,
        public readonly array $metrics = [],
        public readonly array $findings = [],
        ?CarbonImmutable $generatedAt = null,
    ) {
        $this->generatedAt = $generatedAt ?? CarbonImmutable::now();
    }

    public function isEmpty(): bool
    {
        return $this->metrics === [] && $this->findings === [];
    }
}
