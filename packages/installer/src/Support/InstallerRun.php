<?php

declare(strict_types=1);

namespace Capell\Installer\Support;

use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Capell\Installer\Actions\RetainInstallerRunReportAction;
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
        $this->sessions->duringRunUpdate($this->installId, function (): void {
            $this->sessions->lock($this->installId, queued: true);
            $this->persistStatus(InstallerRunStatus::Queued);
        });
    }

    public function startSynchronous(): void
    {
        $this->sessions->duringRunUpdate($this->installId, function (): void {
            $this->sessions->lock($this->installId);
            $this->persistStatus(InstallerRunStatus::Running);
        });
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
        $this->sessions->duringRunUpdate($this->installId, function () use ($inputData, $plan, $firstStepKey, $preflight): void {
            $this->sessions->startStepInstallSession(
                installId: $this->installId,
                inputData: $inputData,
                plan: $plan,
                installStatus: ($firstStepKey === null ? InstallerRunStatus::Complete : InstallerRunStatus::Pending)->value,
                firstStepKey: $firstStepKey,
                preflight: $preflight,
            );
        });
    }

    public function markRunning(?FileLogProgressReporter $reporter = null): void
    {
        $this->transition(InstallerRunStatus::Running, $reporter);
    }

    public function markComplete(?FileLogProgressReporter $reporter = null): void
    {
        $this->transition(InstallerRunStatus::Complete, $reporter);
    }

    public function markFailed(?FileLogProgressReporter $reporter = null): void
    {
        $this->transition(InstallerRunStatus::Failed, $reporter);
    }

    public function cancel(bool $releaseLock = true): void
    {
        $this->sessions->duringRunUpdate($this->installId, function () use ($releaseLock): void {
            RetainInstallerRunReportAction::run($this->installId);
            $this->sessions->clearInstallSession($this->installId);
            $this->persistStatus(InstallerRunStatus::Cancelled);

            // Replacement keeps the old lock until the incoming run overwrites it.
            if ($releaseLock) {
                $this->releaseLock();
            }
        });
    }

    public function releaseLock(): void
    {
        $this->sessions->clearActiveLock($this->installId);
    }

    private function transition(InstallerRunStatus $status, ?FileLogProgressReporter $reporter): void
    {
        $this->sessions->duringRunUpdate($this->installId, function () use ($status, $reporter): void {
            if (! $this->sessions->ownsRun($this->installId)) {
                $this->cancel(releaseLock: false);

                return;
            }

            if ($this->status() === InstallerRunStatus::Cancelled) {
                return;
            }

            if (! $reporter instanceof FileLogProgressReporter) {
                $this->persistStatus($status);
            } else {
                match ($status) {
                    InstallerRunStatus::Running => $reporter->markRunning(),
                    InstallerRunStatus::Complete => $reporter->markComplete(),
                    InstallerRunStatus::Failed => $reporter->markFailed(),
                    default => null,
                };
            }

            if ($status === InstallerRunStatus::Failed) {
                $this->releaseLock();
            }
        });
    }

    private function persistStatus(InstallerRunStatus $status): void
    {
        $this->sessions->putStatus($this->installId, $status->value);
    }
}
