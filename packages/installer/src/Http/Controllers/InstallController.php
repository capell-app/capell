<?php

declare(strict_types=1);

namespace Capell\Installer\Http\Controllers;

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Jobs\RunCapellInstallJob;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\FileLogProgressReporter;
use Capell\Core\Support\Install\InstallInputFactory;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Installer\Actions\BuildInstallerPageDataAction;
use Capell\Installer\Actions\RemoveSetupPackageAction;
use Capell\Installer\Http\Requests\RunInstallStepRequest;
use Capell\Installer\Http\Requests\StoreInstallRequest;
use Capell\Installer\Http\Responses\InstallStepResponse;
use Capell\Installer\Support\AdminUserModelGuard;
use Capell\Installer\Support\InstallerInstallationState;
use Capell\Installer\Support\InstallerOptions;
use Capell\Installer\Support\InstallerRemediation;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class InstallController
{
    private const string REMOVE_INSTALLER_SESSION_KEY = 'capell.installer.can_remove_setup_package';

    public function __construct(
        private readonly InstallerSessionRepository $sessions,
        private readonly InstallerOptions $options,
        private readonly InstallStepResponse $stepResponse,
        private readonly InstallerRemediation $remediation,
        private readonly AdminUserModelGuard $adminUserModelGuard,
    ) {}

    public function show(Request $request): Response
    {
        $hasActiveInstallLock = $this->sessions->hasActiveInstallLock();
        $capellAlreadyInstalled = $request->attributes->get('capellAlreadyInstalled') === true
            || (! $hasActiveInstallLock && $this->capellIsInstalled());
        $canReinstall = $request->attributes->get('capellCanReinstall') === true;

        $request->session()->regenerateToken();

        $pageData = BuildInstallerPageDataAction::run(
            capellAlreadyInstalled: $capellAlreadyInstalled,
            canReinstall: $canReinstall,
        );
        $viewData = $pageData->toViewData();
        $viewData['canRemoveInstaller'] = $this->canRemoveInstaller($request);

        $installId = $viewData['installId'] ?? null;
        if (is_string($installId) && ! $this->canAccessInstall($request, $installId)) {
            $viewData['installId'] = null;
            $viewData['installStatus'] = 'idle';
            $viewData['cancelUrl'] = null;
        }

        return response()
            ->view('capell-installer::install', $viewData)
            ->withHeaders($this->installerSessionHeaders());
    }

    public function store(StoreInstallRequest $request): Response
    {
        $validated = $request->validated();

        $inputData = resolve(InstallInputFactory::class)->fromWebInput(
            $request->normalisedInput(),
            allowWelcomeRoute: true,
            defaultPackageNames: $this->options->configuredDefaultPackageNames(),
        );

        $installId = $validated['install_id'] ?? (string) Str::uuid();
        $runAsJob = (bool) ($validated['run_as_job'] ?? false);

        if (! $this->sessions->cacheStoreIsUsable()) {
            return $this->cacheStoreUnavailableResponse($request);
        }

        if (! $this->canReplaceActiveInstall($request, $installId)) {
            return $this->activeInstallLockedResponse($request);
        }

        $this->grantInstallAccess($request, $installId);

        if ($runAsJob) {
            $queueReporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));
            try {
                if ($this->adminUserModelGuard->hasInstalledAdminPackageSelection($inputData)) {
                    $this->adminUserModelGuard->ensureUserModelSupportsAdminPackage($inputData, $queueReporter);
                }
            } catch (Throwable $throwable) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $throwable->getMessage(),
                        'errors' => ['user_model' => [$throwable->getMessage()]],
                    ], 422);
                }

                return back()->withErrors(['user_model' => $throwable->getMessage()])->withInput();
            }

            $this->sessions->cancelActiveInstallBeforeStarting($installId);

            $this->sessions->lock($installId, queued: true);
            $this->sessions->putStatus($installId, 'queued');
            $this->cacheSuccessSummary($installId, $inputData);

            dispatch(new RunCapellInstallJob($inputData, $installId));

            if ($request->expectsJson()) {
                return response()->json([
                    'installId' => $installId,
                    'status' => 'queued',
                    'progressUrl' => route('capell-installer.progress', ['installId' => $installId]),
                    'progressDataUrl' => route('capell-installer.progress.data', ['installId' => $installId]),
                    'reportUrl' => route('capell-installer.progress.download', ['installId' => $installId]),
                    'redirectUrl' => route('capell-installer.progress', ['installId' => $installId]),
                ]);
            }

            return to_route('capell-installer.progress', ['installId' => $installId]);
        }

        if ($request->expectsJson()) {
            try {
                return $this->prepareStepBasedInstall($installId, $inputData);
            } catch (Throwable $throwable) {
                return response()->json([
                    'message' => $throwable->getMessage(),
                    'errors' => ['user_model' => [$throwable->getMessage()]],
                ], 422);
            }
        }

        // Non-AJAX, non-job: run synchronously and redirect to progress page
        $this->sessions->cancelActiveInstallBeforeStarting($installId);

        $this->sessions->lock($installId);
        $this->sessions->putStatus($installId, 'running');

        $reporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));
        $reporter->markRunning();

        $installCompleted = false;

        try {
            if ($this->adminUserModelGuard->hasInstalledAdminPackageSelection($inputData)) {
                $this->adminUserModelGuard->ensureUserModelSupportsAdminPackage($inputData, $reporter);
            }

            RunInstallAction::run($inputData, $reporter);
            $reporter->markComplete();
            $this->cacheSuccessSummary($installId, $inputData);
            $installCompleted = true;
        } catch (Throwable $throwable) {
            $reporter->error('✗ ' . $throwable->getMessage());
            $reporter->markFailed();
            $this->sessions->clearActiveLock();
        }

        return to_route($installCompleted ? 'capell-installer.success' : 'capell-installer.progress', ['installId' => $installId]);
    }

    public function runStep(RunInstallStepRequest $request): JsonResponse
    {
        $installId = (string) $request->validated('install_id');
        $stepKey = (string) $request->validated('step');

        abort_unless($this->canAccessInstall($request, $installId), 404);

        $inputArray = $this->sessions->input($installId);
        if (! is_array($inputArray)) {
            return $this->stepResponse->gone($installId, 'Install session not found or expired. Please restart the installer.');
        }

        $inputData = InstallInputData::from($inputArray);
        /** @var array<int, array{key: string, label: string}> $plan */
        $plan = $this->sessions->plan($installId);
        $resolvedUserId = $this->sessions->resolvedUserId($installId);

        $reporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));

        if ($this->sessions->status($installId, 'pending') === 'complete') {
            return $this->stepResponse->complete($installId, $stepKey, $reporter->logPath());
        }

        $expectedStepKey = $this->sessions->expectedStepKey($installId, $plan);
        if ($expectedStepKey === null) {
            return $this->stepResponse->gone($installId, 'Install plan not found or expired. Please restart the installer.');
        }

        if ($stepKey !== $expectedStepKey) {
            return $this->stepResponse->outOfSequence($installId, $stepKey, $expectedStepKey, $reporter->logPath());
        }

        $reporter->markRunning();

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        try {
            $reporter->step(InstallPlan::labelForStep($plan, $stepKey) . '…');
            if ($stepKey === InstallPlan::STEP_PREFLIGHT_CHECKS) {
                $preflight = resolve(InstallerPreflight::class)->run($inputData);
                $this->sessions->putPreflightReport($installId, $preflight);
                $this->remediation->reportPreflight($preflight, $reporter);

                if (InstallerPreflight::hasBlockingFailures($preflight['checks'])) {
                    $reporter->markFailed();
                    $this->sessions->clearActiveLock();

                    return $this->stepResponse->failed(
                        $installId,
                        $stepKey,
                        $reporter->logPath(),
                        'Preflight checks failed.',
                        ['remediation' => $this->remediation->preflightRemediation($preflight), 'preflight' => $preflight],
                    );
                }

                $nextStep = InstallPlan::findNextStep($plan, $stepKey);
                $this->sessions->recordCompletedStep($installId, $stepKey, $nextStep);

                return $this->stepResponse->running($installId, $stepKey, $nextStep, $reporter->logPath(), ['preflight' => $preflight]);
            }

            if (($stepKey === InstallPlan::STEP_RESOLVE_USER && $this->adminUserModelGuard->hasInstalledAdminPackageSelection($inputData))
                || InstallPlan::packageNameFromStep($stepKey) === 'capell-app/admin') {
                $this->adminUserModelGuard->ensureUserModelSupportsAdminPackage($inputData, $reporter);
            }

            $newUserId = RunInstallStepAction::run($stepKey, $inputData, $reporter, $resolvedUserId);
            if (is_int($newUserId) && $newUserId !== $resolvedUserId) {
                $this->sessions->putResolvedUserId($installId, $newUserId);
            }
        } catch (Throwable $throwable) {
            $reporter->error('✗ ' . $throwable::class . ': ' . $throwable->getMessage());
            $reporter->error(sprintf('  at %s:%d', $throwable->getFile(), $throwable->getLine()));
            $reporter->markFailed();
            $this->sessions->clearActiveLock();

            return $this->stepResponse->failed(
                $installId,
                $stepKey,
                $reporter->logPath(),
                $throwable->getMessage(),
                ['errorClass' => $throwable::class, 'remediation' => $this->remediation->remediationFor($throwable->getMessage())],
            );
        } finally {
            $this->sessions->recordStepPeakMemory($installId, $stepKey, memory_get_peak_usage(true));
        }

        $nextStep = InstallPlan::findNextStep($plan, $stepKey);
        $this->sessions->recordCompletedStep($installId, $stepKey, $nextStep);

        if ($nextStep === null) {
            $reporter->markComplete();
            $this->cacheSuccessSummary($installId, $inputData);
            $this->sessions->clearActiveLock();

            return $this->stepResponse->complete($installId, $stepKey, $reporter->logPath());
        }

        return $this->stepResponse->running($installId, $stepKey, $nextStep, $reporter->logPath());
    }

    public function progress(Request $request, string $installId): View
    {
        abort_unless($this->canAccessInstall($request, $installId) && $this->sessions->hasInstallSessionState($installId), 404);

        $status = $this->sessions->status($installId, 'running');
        /** @var view-string $progressView */
        $progressView = 'capell-installer::progress';

        return view($progressView, [
            'installId' => $installId,
            'installStatus' => $status,
            'reportDownloadFilename' => $this->reportDownloadFilename($installId),
            'reportUrl' => route('capell-installer.progress.download', ['installId' => $installId]),
        ]);
    }

    public function success(Request $request, string $installId): Response
    {
        abort_unless($this->canAccessInstall($request, $installId), 404);

        abort_if($this->sessions->status($installId) !== 'complete'
            || ! $this->sessions->hasSuccessSummary($installId), 404);

        $successSummary = $this->sessions->pullSuccessSummary($installId);
        $this->allowInstallerRemoval($request);

        return response()->view('capell-installer::success', [
            'installId' => $installId,
            'primaryAdmin' => $successSummary['primaryAdmin'] ?? null,
            'roleUsersCreated' => ($successSummary['roleUsersCreated'] ?? false) === true,
            'canRemoveInstaller' => true,
        ])->withHeaders($this->installerSessionHeaders());
    }

    public function progressData(Request $request, string $installId): JsonResponse
    {
        abort_unless($this->canAccessInstall($request, $installId) && $this->sessions->hasInstallSessionState($installId), 404);

        $lines = $this->sessions->lines($installId);
        $status = $this->sessions->status($installId, 'running');

        if (in_array($status, ['complete', 'failed', 'cancelled'], true)) {
            $this->sessions->clearActiveLock();
        }

        if (in_array($status, ['failed', 'cancelled'], true)) {
            $this->sessions->forgetSuccessSummary($installId);
        }

        return response()->json([
            'installId' => $installId,
            'status' => $status,
            'lines' => $lines,
            'redirectUrl' => $status === 'complete'
                ? route('capell-installer.success', ['installId' => $installId])
                : null,
        ]);
    }

    public function destroy(Request $request): Response
    {
        abort_unless($this->canRemoveInstaller($request), 404);

        $request->session()->forget(self::REMOVE_INSTALLER_SESSION_KEY);

        return redirect()->to(RemoveSetupPackageAction::run());
    }

    public function report(Request $request, string $installId): Response
    {
        if (! $this->canAccessInstall($request, $installId) || ! $this->sessions->hasInstallSessionState($installId)) {
            return response()->json(['error' => 'Install report not found.'], 404);
        }

        try {
            $inputArray = $this->sessions->input($installId);
            $inputData = is_array($inputArray) ? InstallInputData::from($inputArray) : null;
            $preflight = $this->sessions->preflightReport($installId);

            if (! is_array($preflight)) {
                $preflight = resolve(InstallerPreflight::class)->run($inputData);
            }

            $status = $this->sessions->status($installId);
            $lines = $this->sessions->lines($installId);

            $payload = [
                'installId' => $installId,
                'status' => $status,
                'environment' => $preflight['environment'] ?? [],
                'preflight' => $preflight,
                'plan' => $this->sessions->plan($installId),
                'diagnostics' => ['steps' => $this->sessions->stepDiagnostics($installId)],
                'selected' => [
                    'packages' => $inputData->packages ?? [],
                    'extraPackages' => $inputData->extraPackages ?? [],
                    'languages' => $inputData->languages ?? [],
                    'seedDefaultData' => $inputData->seedDefaultData ?? null,
                    'demoContent' => $inputData->demoContent ?? null,
                    'generateSitemap' => $inputData->generateSitemap ?? null,
                    'generateStaticSite' => $inputData->generateStaticSite ?? null,
                    'installFilamentPanel' => $inputData->installFilamentPanel ?? null,
                    'integrateAdminPanel' => $inputData->integrateAdminPanel ?? null,
                    'rebuildResources' => $inputData->rebuildResources ?? null,
                    'installDeveloperTooling' => $inputData->installDeveloperTooling ?? null,
                    'configureBoostDeveloperTooling' => $inputData->configureBoostDeveloperTooling ?? null,
                    'additionalUsers' => collect($inputData->additionalUsers ?? [])
                        ->map(fn (NewUserData $user): array => [
                            'name' => $user->name,
                            'email' => $user->email,
                            'roleName' => $user->roleName,
                        ])
                        ->all(),
                ],
                'lines' => $lines,
                'remediations' => $this->remediation->remediationsForLines($lines),
            ];
        } catch (Throwable $throwable) {
            return response()->json(['error' => $throwable->getMessage()], 500);
        }

        return response()->json($payload, 200, [
            'Content-Disposition' => sprintf('attachment; filename="%s"', $this->reportDownloadFilename($installId)),
        ]);
    }

    public function cancel(Request $request, string $installId): Response
    {
        abort_unless(Str::isUuid($installId), 404);
        abort_unless($this->canAccessInstall($request, $installId), 404);

        $this->sessions->clearActiveLock($installId);

        $this->sessions->clearInstallSession($installId);

        if ($request->expectsJson()) {
            return response()->json(['status' => 'cancelled']);
        }

        return to_route('capell-installer.show');
    }

    private function reportDownloadFilename(string $installId): string
    {
        return sprintf('capell-install-%s.json', $installId);
    }

    private function prepareStepBasedInstall(string $installId, InstallInputData $inputData): JsonResponse
    {
        $plan = InstallPlan::build($inputData);
        $firstStepKey = $plan[0]['key'] ?? null;
        $installStatus = is_string($firstStepKey) ? 'pending' : 'complete';

        $logPath = storage_path(sprintf('logs/capell-install-%s.log', $installId));
        $reporter = new FileLogProgressReporter($installId, new CacheProgressReporter($installId));

        if ($this->adminUserModelGuard->hasInstalledAdminPackageSelection($inputData)) {
            $this->adminUserModelGuard->ensureUserModelSupportsAdminPackage($inputData, $reporter);
        }

        $this->sessions->cancelActiveInstallBeforeStarting($installId);

        $this->sessions->startStepInstallSession(
            installId: $installId,
            inputData: $inputData,
            plan: $plan,
            installStatus: $installStatus,
            firstStepKey: $firstStepKey,
            preflight: resolve(InstallerPreflight::class)->run($inputData),
        );

        return response()->json([
            'installId' => $installId,
            'status' => $installStatus,
            'plan' => $plan,
            'nextStep' => $firstStepKey,
            'progressUrl' => route('capell-installer.progress', ['installId' => $installId]),
            'progressDataUrl' => route('capell-installer.progress.data', ['installId' => $installId]),
            'reportUrl' => route('capell-installer.progress.download', ['installId' => $installId]),
            'successUrl' => route('capell-installer.success', ['installId' => $installId]),
            'runStepUrl' => route('capell-installer.run-step'),
            'cancelUrl' => route('capell-installer.cancel', ['installId' => $installId]),
            'logPath' => $logPath,
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function installerSessionHeaders(): array
    {
        return [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0, private',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }

    private function grantInstallAccess(Request $request, string $installId): void
    {
        if (! Str::isUuid($installId)) {
            return;
        }

        $request->session()->put($this->installAccessSessionKey($installId), true);
    }

    private function canReplaceActiveInstall(Request $request, string $installId): bool
    {
        $activeInstallId = $this->sessions->activeInstallId();

        if ($activeInstallId === null) {
            return true;
        }

        if ($activeInstallId === $installId) {
            return true;
        }

        return $this->canAccessInstall($request, $activeInstallId);
    }

    private function activeInstallLockedResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Another install is already running in a different browser session.',
            ], 409);
        }

        return back()->withErrors([
            'install' => 'Another install is already running in a different browser session.',
        ]);
    }

    private function canAccessInstall(Request $request, string $installId): bool
    {
        return Str::isUuid($installId)
            && $request->session()->get($this->installAccessSessionKey($installId)) === true;
    }

    private function allowInstallerRemoval(Request $request): void
    {
        $request->session()->put(self::REMOVE_INSTALLER_SESSION_KEY, true);
    }

    private function canRemoveInstaller(Request $request): bool
    {
        return $request->session()->get(self::REMOVE_INSTALLER_SESSION_KEY) === true;
    }

    private function installAccessSessionKey(string $installId): string
    {
        return sprintf('capell.install.%s.access', $installId);
    }

    private function cacheSuccessSummary(string $installId, InstallInputData $inputData): void
    {
        $this->sessions->putSuccessSummary($installId, [
            'primaryAdmin' => $this->primaryAdminSummary($inputData),
            'roleUsersCreated' => $inputData->additionalUsers !== [],
        ]);
    }

    private function primaryAdminSummary(InstallInputData $inputData): ?string
    {
        if ($inputData->newUser instanceof NewUserData) {
            return sprintf('%s <%s>', $inputData->newUser->name, $inputData->newUser->email);
        }

        if ($inputData->userId === null || ! $this->options->usersTableExists()) {
            return null;
        }

        try {
            $userModel = $this->options->userModel();
            $user = $userModel::query()->find($inputData->userId, ['id', 'name', 'email']);

            if (! $user instanceof Model) {
                return null;
            }

            return sprintf('%s <%s>', (string) $user->getAttribute('name'), (string) $user->getAttribute('email'));
        } catch (Throwable) {
            return null;
        }
    }

    private function capellIsInstalled(): bool
    {
        return InstallerInstallationState::capellIsInstalled();
    }

    private function cacheStoreUnavailableResponse(Request $request): Response
    {
        $message = 'CACHE_STORE=database requires the cache table before the web installer can track progress.';
        $remediation = 'Run php artisan cache:table && php artisan migrate, or temporarily set CACHE_STORE=file or CACHE_STORE=array until migrations have run.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => ['cache_store' => [$remediation]],
            ], 422);
        }

        return back()->withErrors(['cache_store' => $message . ' ' . $remediation])->withInput();
    }
}
