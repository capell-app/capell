<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Diagnostics;

use Capell\Admin\Data\Reports\ReportFindingData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class OperationsCenterData extends Data
{
    public readonly CarbonImmutable $generatedAt;

    /**
     * @param  array<string, list<ReportFindingData>>  $categories
     */
    public function __construct(
        public readonly ReportSnapshotData $snapshot,
        public readonly array $categories,
    ) {
        $this->generatedAt = $snapshot->generatedAt;
    }
}
