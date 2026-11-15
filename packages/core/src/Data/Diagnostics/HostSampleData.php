<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Spatie\LaravelData\Data;

/**
 * One reading of the server Capell runs on. Byte fields are null where the
 * platform cannot report them, and a null never counts as a problem.
 */
final class HostSampleData extends Data
{
    /**
     * @param  list<array{pid: int, user: string, cpu: float, command: string}>  $topProcesses  Busiest processes since the previous sample.
     * @param  list<array{command: string, seconds: float}>  $slowestSchedulerTasks
     */
    public function __construct(
        public readonly int $takenAt,
        public readonly string $node,
        public readonly int $cores,
        public readonly float $load1,
        public readonly float $load5,
        public readonly float $load15,
        public readonly ?int $memoryTotalBytes = null,
        public readonly ?int $memoryAvailableBytes = null,
        public readonly ?int $swapTotalBytes = null,
        public readonly ?int $swapUsedBytes = null,
        public readonly array $topProcesses = [],
        public readonly ?float $schedulerCpuSeconds = null,
        public readonly ?float $schedulerWallSeconds = null,
        public readonly int $schedulerTasks = 0,
        public readonly int $schedulerProcesses = 0,
        public readonly array $slowestSchedulerTasks = [],
    ) {}

    public function loadRatio(): float
    {
        return $this->load5 / max(1, $this->cores);
    }

    public function memoryAvailablePercent(): ?float
    {
        if ($this->memoryTotalBytes === null || $this->memoryAvailableBytes === null || $this->memoryTotalBytes <= 0) {
            return null;
        }

        return $this->memoryAvailableBytes / $this->memoryTotalBytes * 100;
    }

    public function swapUsedPercent(): ?float
    {
        if ($this->swapTotalBytes === null || $this->swapUsedBytes === null || $this->swapTotalBytes <= 0) {
            return null;
        }

        return $this->swapUsedBytes / $this->swapTotalBytes * 100;
    }

    /** CPU seconds one scheduler run used, as a share of what all cores supply in a minute. */
    public function schedulerCpuRatio(): ?float
    {
        if ($this->schedulerCpuSeconds === null) {
            return null;
        }

        return $this->schedulerCpuSeconds / (max(1, $this->cores) * 60);
    }
}
