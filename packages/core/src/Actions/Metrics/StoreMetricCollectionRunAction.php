<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Metrics;

use Capell\Core\Enums\Metrics\MetricCollectionRunStatus;
use Capell\Core\Models\MetricCollectionRun;
use Carbon\CarbonImmutable;

final class StoreMetricCollectionRunAction
{
    public function execute(
        string $day,
        string $ownerPackage,
        string $collectorKey,
        string $definitionHash,
        MetricCollectionRunStatus $status,
        CarbonImmutable $startedAt,
        ?CarbonImmutable $completedAt = null,
        ?string $sourceWatermark = null,
        ?string $sourceChecksum = null,
        ?string $errorSummary = null,
        ?MetricCollectionRun $run = null,
    ): MetricCollectionRun {
        $run ??= new MetricCollectionRun;
        $run->fill([
            'day' => $day,
            'owner_package' => $ownerPackage,
            'collector_key' => $collectorKey,
            'definition_hash' => $definitionHash,
            'status' => $status,
            'source_watermark' => $sourceWatermark,
            'source_checksum' => $sourceChecksum,
            'error_summary' => $errorSummary,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);
        $run->save();

        return $run;
    }
}
