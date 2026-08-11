<?php

declare(strict_types=1);

namespace Capell\Core\Data\Health;

use Capell\Core\Enums\Health\HealthStatus;
use Override;
use Spatie\LaravelData\Data;

final class HealthReportData extends Data
{
    /** @param list<HealthCheckResultData> $checks */
    public function __construct(public array $checks) {}

    public function status(): HealthStatus
    {
        foreach ([HealthStatus::TimedOut, HealthStatus::Error, HealthStatus::Failed, HealthStatus::Warning] as $status) {
            if (array_any($this->checks, static fn (HealthCheckResultData $check): bool => $check->status === $status)) {
                return $status;
            }
        }

        return HealthStatus::Healthy;
    }

    /** @return array<string, list<HealthCheckResultData>> */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->checks as $check) {
            $groups[$check->category][] = $check;
        }

        ksort($groups);

        return $groups;
    }

    /** @return array{status: string, checks: list<array<string, mixed>>} */
    #[Override]
    public function toArray(): array
    {
        return ['status' => $this->status()->value, 'checks' => array_map(static fn (HealthCheckResultData $check): array => $check->toArray(), $this->checks)];
    }
}
