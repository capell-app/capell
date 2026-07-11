<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Illuminate\Support\Collection;
use Override;
use Spatie\LaravelData\Data;

final class DoctorReportData extends Data
{
    /**
     * @param  Collection<int, DoctorCheckResultData>  $checks
     */
    public function __construct(
        public string $status,
        public Collection $checks,
    ) {}

    public function passed(): bool
    {
        return $this->status === 'passed';
    }

    /**
     * @return array{status: string, checks: array<int, array{label: string, passed: bool, message: string, remediation: string|null}>}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'checks' => $this->checks
                ->map(fn (DoctorCheckResultData $check): array => $check->toArray())
                ->values()
                ->all(),
        ];
    }
}
