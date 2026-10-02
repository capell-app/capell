<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Capell\Installer\Enums\InstallerRunStatus;

final readonly class InstallerRun
{
    public function __construct(
        private InstallerSessionRepository $sessions,
        public string $installId,
    ) {}

    public function status(InstallerRunStatus $default = InstallerRunStatus::Unknown): InstallerRunStatus
    {
        // Queue workers and Core reporters share persisted state; do not retain a snapshot.
        return InstallerRunStatus::from($this->sessions->status($this->installId, $default->value));
    }

    public function startQueued(): void
    {
        $this->sessions->cancelActiveInstallBeforeStarting($this->installId);
        $this->sessions->lock($this->installId, queued: true);
        $this->persistStatus(InstallerRunStatus::Queued);
    }

    public function startSynchronous(): void
    {
        $this->sessions->cancelActiveInstallBeforeStarting($this->installId);
        $this->sessions->lock($this->installId);
        $this->persistStatus(InstallerRunStatus::Running);
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>  $preflight
     */
    public function startBrowserSteps(
        InstallInputData $inputData,
        array $plan,
        ?string $firstStepKey,
        array $preflight,
    ): void {
        $this->sessions->cancelActiveInstallBeforeStarting($this->installId);
        $this->sessions->startStepInstallSession(
            installId: $this->installId,
            inputData: $inputData,
            plan: $plan,
            installStatus: ($firstStepKey === null ? InstallerRunStatus::Complete : InstallerRunStatus::Pending)->value,
            firstStepKey: $firstStepKey,
            preflight: $preflight,
        );
    }

    public function markRunning(?FileLogProgressReporter $reporter = null): void
    {
        // Core reporters allow retries to update terminal runs without checking prior status.
        if ($reporter instanceof FileLogProgressReporter) {
            $reporter->markRunning();
        } else {
            $this->persistStatus(InstallerRunStatus::Running);
        }
    }

    public function markComplete(?FileLogProgressReporter $reporter = null): void
    {
        if ($reporter instanceof FileLogProgressReporter) {
            $reporter->markComplete();
        } else {
            $this->persistStatus(InstallerRunStatus::Complete);
        }
    }

    public function markFailed(?FileLogProgressReporter $reporter = null): void
    {
        if ($reporter instanceof FileLogProgressReporter) {
            $reporter->markFailed();
        } else {
            $this->persistStatus(InstallerRunStatus::Failed);
        }

        $this->releaseLock();
    }

    public function cancel(bool $releaseLock = true): void
    {
        $this->sessions->clearInstallSession($this->installId);
        $this->persistStatus(InstallerRunStatus::Cancelled);

        // Replacement keeps the old lock until the incoming run overwrites it.
        if ($releaseLock) {
            $this->releaseLock();
        }
    }

    public function releaseLock(): void
    {
        $this->sessions->clearActiveLock($this->installId);
    }

    private function persistStatus(InstallerRunStatus $status): void
    {
        $this->sessions->putStatus($this->installId, $status->value);
    }
}
