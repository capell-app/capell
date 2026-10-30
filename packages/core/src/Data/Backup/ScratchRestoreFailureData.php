<?php

declare(strict_types=1);

namespace Capell\Core\Data\Backup;

use Capell\Core\Enums\Backup\ScratchRestoreStage;
use Spatie\LaravelData\Data;

/**
 * What a failed scratch restore left behind, so an operator can inspect it and
 * retry safely. Every string is redacted before it is stored here.
 */
final class ScratchRestoreFailureData extends Data
{
    /**
     * @param  'process'|'invalid_json'|'checks'|null  $doctorFailure
     * @param  list<array{id: string, label: string, message: string, remediation: string|null}>  $failedChecks
     */
    public function __construct(
        public ScratchRestoreStage $stage,
        public string $snapshotId,
        public string $database,
        public ?string $mediaDisk,
        public ?string $mediaPrefix,
        public int $mediaFilesRestored,
        public int $mediaFilesExpected,
        public string $reason,
        public ?string $doctorFailure = null,
        public ?int $exitCode = null,
        public array $failedChecks = [],
    ) {}

    /**
     * @param  'process'|'invalid_json'|'checks'  $kind
     * @param  list<array{id: string, label: string, message: string, remediation: string|null}>  $failedChecks
     */
    public function withDoctorResult(string $kind, string $reason, ?int $exitCode = null, array $failedChecks = []): self
    {
        return new self(
            $this->stage,
            $this->snapshotId,
            $this->database,
            $this->mediaDisk,
            $this->mediaPrefix,
            $this->mediaFilesRestored,
            $this->mediaFilesExpected,
            $reason,
            $kind,
            $exitCode,
            $failedChecks,
        );
    }
}
