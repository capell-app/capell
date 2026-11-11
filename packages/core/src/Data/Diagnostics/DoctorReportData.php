<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Capell\Core\Enums\Diagnostics\DoctorCheckOutcome;
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

    /**
     * Build a report whose status follows the checks: only a failed check
     * fails the report, warnings leave it passed.
     *
     * @param  Collection<int, DoctorCheckResultData>  $checks
     */
    public static function fromChecks(Collection $checks): self
    {
        $values = $checks->values();

        return new self(
            status: $values->contains(fn (DoctorCheckResultData $check): bool => $check->outcome() === DoctorCheckOutcome::Failed) ? 'failed' : 'passed',
            checks: $values,
        );
    }

    public function passed(): bool
    {
        return $this->status === 'passed';
    }

    /**
     * @return Collection<int, DoctorCheckResultData>
     */
    public function warnings(): Collection
    {
        return $this->checks
            ->filter(fn (DoctorCheckResultData $check): bool => $check->isWarning())
            ->values();
    }

    public function warningCount(): int
    {
        return $this->warnings()->count();
    }

    public function warningSummary(): DoctorWarningSummaryData
    {
        return new DoctorWarningSummaryData($this->warningCount());
    }

    /**
     * @return array{status: string, warning_count: int, checks: array<int, array{id: string, severity: string, outcome: string, label: string, passed: bool, message: string, remediation: string|null, evidence: array<string, mixed>}>}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'warning_count' => $this->warningCount(),
            'checks' => $this->checks
                ->map(fn (DoctorCheckResultData $check): array => $check->toArray())
                ->values()
                ->all(),
        ];
    }
}
