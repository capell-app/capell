<?php

declare(strict_types=1);

namespace Capell\Installer\Actions;

use Capell\Core\Actions\AssertQueueConnectionReadyAction;
use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Jobs\RunCapellInstallJob;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallReportRedactor;
use Capell\Installer\Data\InstallerRunStartData;
use Capell\Installer\Enums\InstallerRunMode;
use Capell\Installer\Enums\InstallerRunStatus;
use Capell\Installer\Support\AdminUserModelGuard;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class StartInstallerRunAction
{
    use AsFake;
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly AdminUserModelGuard $adminUserModelGuard,
    ) {}

    public function handle(
        string $installId,
        InstallInputData $inputData,
        InstallerRunMode $mode,
        array $tableChanges = [],
    ): InstallerRunStartData {
        if ($inputData->freshInstall) {
            throw ValidationException::withMessages(['fresh_install' => __('capell-installer::installer.fresh_install_cli_only')]);
        }

        return match ($mode) {
            InstallerRunMode::BrowserSteps => $this->startBrowserSteps($installId, $inputData),
            InstallerRunMode::Queued => $this->startQueued($installId, $inputData, $tableChanges),
            InstallerRunMode::Synchronous => $this->startSynchronous($installId, $inputData),
        };
    }

    private function startQueued(string $installId, InstallInputData $inputData, array $tableChanges): InstallerRunStartData
    {
        AssertQueueConnectionReadyAction::run();

        $reporter = $this->reporter($installId, $inputData);
        $this->ensureAdminUserModelIsReady($inputData, $reporter);

        $this->sessions->run($installId)->startQueued();

        $this->sessions->duringRunUpdate($installId, function () use ($installId, $inputData): void {
            if ($this->sessions->ownsRun($installId) && $this->sessions->status($installId) !== InstallerRunStatus::Cancelled->value) {
                CacheInstallerSuccessSummaryAction::run($installId, $inputData);
            }
        });

        dispatch(new RunCapellInstallJob($inputData, $installId, $tableChanges));

        return new InstallerRunStartData(
            installId: $installId,
            mode: InstallerRunMode::Queued,
            status: InstallerRunStatus::Queued->value,
            plan: [],
            nextStep: null,
            logPath: $reporter->logPath(),
            completed: false,
        );
    }

    private function startBrowserSteps(string $installId, InstallInputData $inputData): InstallerRunStartData
    {
        $plan = InstallPlan::build($inputData);
        $firstStepKey = $plan[0]['key'] ?? null;
        $installStatus = is_string($firstStepKey) ? InstallerRunStatus::Pending : InstallerRunStatus::Complete;
        $reporter = $this->reporter($installId, $inputData);
        $preflight = resolve(InstallerPreflight::class)->run($inputData);

        $this->ensureAdminUserModelIsReady($inputData, $reporter);
        $this->sessions->run($installId)->startBrowserSteps(
            inputData: $inputData,
            plan: $plan,
            firstStepKey: is_string($firstStepKey) ? $firstStepKey : null,
            preflight: $preflight,
        );

        return new InstallerRunStartData(
            installId: $installId,
            mode: InstallerRunMode::BrowserSteps,
            status: $installStatus->value,
            plan: $plan,
            nextStep: is_string($firstStepKey) ? $firstStepKey : null,
            logPath: $reporter->logPath(),
            completed: $installStatus === InstallerRunStatus::Complete,
            preflight: $preflight,
        );
    }

    private function startSynchronous(string $installId, InstallInputData $inputData): InstallerRunStartData
    {
        $run = $this->sessions->run($installId);
        $run->startSynchronous();

        $reporter = $this->reporter($installId, $inputData);

        $completed = false;

        try {
            $admitted = $this->sessions->duringRunUpdate($installId, function () use ($installId, $run, $reporter): bool {
                if (! $this->sessions->ownsRun($installId)) {
                    $run->cancel(releaseLock: false);

                    return false;
                }

                if ($run->status() !== InstallerRunStatus::Running) {
                    return false;
                }

                $run->markRunning($reporter);

                return true;
            });
            if (! $admitted) {
                return new InstallerRunStartData(installId: $installId, mode: InstallerRunMode::Synchronous, status: InstallerRunStatus::Cancelled->value, plan: [], nextStep: null, logPath: $reporter->logPath(), completed: false);
            }

            $this->ensureAdminUserModelIsReady($inputData, $reporter);
            RunInstallAction::run($inputData, $reporter);
            $completed = $this->sessions->duringRunUpdate($installId, function () use ($installId, $inputData, $run, $reporter): bool {
                if (! $this->sessions->ownsRun($installId)) {
                    $run->cancel(releaseLock: false);

                    return false;
                }

                if ($run->status() === InstallerRunStatus::Cancelled) {
                    return false;
                }

                $run->markComplete($reporter);
                CacheInstallerSuccessSummaryAction::run($installId, $inputData);

                return true;
            });
        } catch (Throwable $throwable) {
            $this->sessions->duringRunUpdate($installId, function () use ($installId, $run, $reporter, $throwable): void {
                $reporter->error('✗ ' . $throwable->getMessage());
                if (! $this->sessions->ownsRun($installId)) {
                    $run->cancel(releaseLock: false);
                } elseif ($run->status() !== InstallerRunStatus::Cancelled) {
                    $run->markFailed($reporter);
                }
            });
        }

        return new InstallerRunStartData(
            installId: $installId,
            mode: InstallerRunMode::Synchronous,
            status: $run->status()->value,
            plan: [],
            nextStep: null,
            logPath: $reporter->logPath(),
            completed: $completed,
        );
    }

    private function ensureAdminUserModelIsReady(
        InstallInputData $inputData,
        FileLogProgressReporter $reporter,
    ): void {
        if ($this->adminUserModelGuard->hasInstalledAdminPackageSelection($inputData)) {
            $this->adminUserModelGuard->ensureUserModelSupportsAdminPackage($inputData, $reporter);
        }
    }

    private function reporter(string $installId, InstallInputData $inputData): FileLogProgressReporter
    {
        return new FileLogProgressReporter($installId, new CacheProgressReporter($installId, redactionSecrets: InstallReportRedactor::secrets($inputData->toArray())));
    }
}
