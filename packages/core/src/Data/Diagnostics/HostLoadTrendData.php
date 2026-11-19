<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Spatie\LaravelData\Data;

/** What the hourly rollups say about one server over a number of days. Load ratios are load divided by core count. */
final class HostLoadTrendData extends Data
{
    /**
     * @param  array{from: int, to: int, ratio: float}|null  $busiestWindow  Two-hour window, as local clock hours, with the highest average load ratio.
     * @param  list<array{command: string, seconds: float}>  $busiestTasks
     */
    public function __construct(
        public readonly int $days,
        public readonly int $hours,
        public readonly int $cores,
        public readonly float $averageRatio,
        public readonly float $peakRatio,
        public readonly int $peakAt,
        public readonly ?array $busiestWindow,
        public readonly ?float $lowestMemoryAvailablePercent,
        public readonly ?float $peakSwapUsedPercent,
        public readonly ?int $peakQueueDepth,
        public readonly array $busiestTasks,
    ) {}
}
