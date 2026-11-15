<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Capell\Core\Enums\Diagnostics\HostMonitorStatus;
use Spatie\LaravelData\Data;

final class HostAssessmentData extends Data
{
    /**
     * @param  list<array{signal: string, status: string, message: string}>  $reasons
     */
    public function __construct(
        public readonly HostMonitorStatus $status,
        public readonly array $reasons,
        public readonly ?HostSampleData $sample,
        public readonly ?int $sinceTimestamp = null,
    ) {}

    public function needsAttention(): bool
    {
        return $this->status !== HostMonitorStatus::Ok;
    }
}
