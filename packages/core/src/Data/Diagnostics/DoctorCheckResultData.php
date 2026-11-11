<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Capell\Core\Enums\Diagnostics\DoctorCheckOutcome;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Illuminate\Support\Str;
use Override;
use Spatie\LaravelData\Data;

final class DoctorCheckResultData extends Data
{
    public readonly string $id;

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public readonly string $label,
        public readonly bool $passed,
        public readonly string $message,
        public readonly ?string $remediation = null,
        ?string $id = null,
        public readonly DoctorCheckSeverity $severity = DoctorCheckSeverity::Critical,
        public readonly array $evidence = [],
    ) {
        $normalizedId = is_string($id) ? trim($id) : '';
        $this->id = $normalizedId !== ''
            ? $normalizedId
            : 'legacy.' . Str::of($label)->lower()->slug('.')->toString();
    }

    public function isCriticalFailure(): bool
    {
        return ! $this->passed && $this->severity === DoctorCheckSeverity::Critical;
    }

    /**
     * Severity decides whether a problem blocks the install: only a critical
     * failure does. Any other unmet check, and a passing check that reports
     * warnings in its evidence, is a warning the site can run with.
     */
    public function outcome(): DoctorCheckOutcome
    {
        if ($this->isCriticalFailure()) {
            return DoctorCheckOutcome::Failed;
        }

        $reportsWarnings = is_int($this->evidence['warning_count'] ?? null) && $this->evidence['warning_count'] > 0;

        return ! $this->passed || $reportsWarnings
            ? DoctorCheckOutcome::Warning
            : DoctorCheckOutcome::Passed;
    }

    public function isWarning(): bool
    {
        return $this->outcome() === DoctorCheckOutcome::Warning;
    }

    /**
     * @return array{id: string, severity: string, outcome: string, label: string, passed: bool, message: string, remediation: string|null, evidence: array<string, mixed>}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity->value,
            'outcome' => $this->outcome()->value,
            'label' => $this->label,
            'passed' => $this->passed,
            'message' => $this->message,
            'remediation' => $this->remediation,
            'evidence' => $this->evidence,
        ];
    }
}
