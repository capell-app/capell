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
use Capell\Installer\Data\InstallerRunStartData;
use Capell\Installer\Enums\InstallerRunMode;
use Capell\Installer\Support\AdminUserModelGuard;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class StartInstallerRunAction
{
    use AsObject;

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly AdminUserModelGuard $adminUserModelGuard,
        private readonly CacheInstallerSuccessSummaryAction $cacheSuccessSummary,
    ) {}

    public function handle(
        string $installId,
        InstallInputData $inputData,
        InstallerRunMode $mode,
    ): InstallerRunStartData {
        return match ($mode) {
            InstallerRunMode::BrowserSteps => $this->startBrowserSteps($installId, $inputData),
            InstallerRunMode::Queued => $this->startQueued($installId, $inputData),
            InstallerRunMode::Synchronous => $this->startSynchronous($installId, $inputData),
        };
    }

    private function startQueued(string $installId, InstallInputData $inputData): InstallerRunStartData
    {
        AssertQueueConnectionReadyAction::run();

        $reporter = $this->reporter($installId);
        $this->ensureAdminUserModelIsReady($inputData, $reporter);

        $this->sessions->cancelActiveInstallBeforeStarting($installId);
        $this->sessions->lock($installId, queued: true);
        $this->sessions->putStatus($installId, 'queued');
        $this->cacheSuccessSummary->handle($installId, $inputData);

        dispatch(new RunCapellInstallJob($inputData, $installId));

        return new InstallerRunStartData(
            installId: $installId,
            mode: InstallerRunMode::Queued,
            status: 'queued',
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
        $installStatus = is_string($firstStepKey) ? 'pending' : 'complete';
        $reporter = $this->reporter($installId);

        $this->ensureAdminUserModelIsReady($inputData, $reporter);
        $this->sessions->cancelActiveInstallBeforeStarting($installId);
        $this->sessions->startStepInstallSession(
            installId: $installId,
            inputData: $inputData,
            plan: $plan,
            installStatus: $installStatus,
            firstStepKey: is_string($firstStepKey) ? $firstStepKey : null,
            preflight: resolve(InstallerPreflight::class)->run($inputData),
        );

        return new InstallerRunStartData(
            installId: $installId,
            mode: InstallerRunMode::BrowserSteps,
            status: $installStatus,
            plan: $plan,
            nextStep: is_string($firstStepKey) ? $firstStepKey : null,
            logPath: $reporter->logPath(),
            completed: $installStatus === 'complete',
        );
    }

    private function startSynchronous(string $installId, InstallInputData $inputData): InstallerRunStartData
    {
        $this->sessions->cancelActiveInstallBeforeStarting($installId);
        $this->sessions->lock($installId);
        $this->sessions->putStatus($installId, 'running');

        $reporter = $this->reporter($installId);
        $reporter->markRunning();
        $completed = false;

        try {
            $this->ensureAdminUserModelIsReady($inputData, $reporter);
            RunInstallAction::run($inputData, $reporter);
            $reporter->markComplete();
            $this->cacheSuccessSummary->handle($installId, $inputData);
            $completed = true;
        } catch (Throwable $throwable) {
            $reporter->error('✗ ' . $throwable->getMessage());
            $reporter->markFailed();
            $this->sessions->clearActiveLock($installId);
        }

        return new InstallerRunStartData(
            installId: $installId,
            mode: InstallerRunMode::Synchronous,
            status: $completed ? 'complete' : 'failed',
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

    private function reporter(string $installId): FileLogProgressReporter
    {
        return new FileLogProgressReporter($installId, new CacheProgressReporter($installId));
    }
}
