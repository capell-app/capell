<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Spatie\LaravelData\Data;

/**
 * Setting names and numbers measured on one server. A null means the value could
 * not be read, which is reported as "not measured" and never guessed.
 */
final class ServerSettingsData extends Data
{
    public function __construct(
        public readonly int $cores,
        public readonly ?int $memoryTotalBytes = null,
        public readonly ?float $swapPeakUsedPercent = null,
        public readonly bool $production = false,
        public readonly bool $opcacheEnabled = false,
        public readonly ?bool $validateTimestamps = null,
        public readonly ?int $opcacheMemoryBytes = null,
        public readonly ?int $opcacheMemoryUsedBytes = null,
        public readonly ?int $internedBufferBytes = null,
        public readonly ?int $internedUsedBytes = null,
        public readonly ?int $maxAcceleratedFiles = null,
        public readonly ?int $cachedScripts = null,
        public readonly ?int $fpmMaxChildren = null,
        public readonly ?int $fpmWorkerRssBytes = null,
        public readonly ?int $queueWorkers = null,
        public readonly ?int $queueWorkerRssBytes = null,
        public readonly ?int $queuePeakDepth = null,
        public readonly ?int $queueHistoryHours = null,
    ) {}
}
