<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Jobs\RunCapellInstallJob;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Installer\Actions\AdvanceInstallerRunAction;
use Capell\Installer\Actions\BuildInstallerRunReportAction;
use Capell\Installer\Actions\CancelInstallerRunAction;
use Capell\Installer\Actions\ReadInstallerRunProgressAction;
use Capell\Installer\Actions\StartInstallerRunAction;
use Capell\Installer\Data\InstallerRunProgressData;
use Capell\Installer\Data\InstallerRunReportData;
use Capell\Installer\Data\InstallerRunStartData;
use Capell\Installer\Data\InstallerRunStepData;
use Capell\Installer\Enums\InstallerRunMode;
use Capell\Installer\Enums\InstallerRunStepResultCode;
use Capell\Installer\Support\InstallerSessionRepository;
use Capell\Installer\Support\Preflight\InstallerPreflight;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

function installerRunInput(): InstallInputData
{
    return new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
}

/** @return array<string, mixed> */
function installerPreflightReport(): array
{
    return [
        'status' => 'pass',
        'environment' => ['php' => PHP_VERSION],
        'groups' => ['blocking' => [], 'advisory' => []],
        'checks' => [],
    ];
}

/** @param array<string, mixed> $report */
function bindInstallerRunPreflight(array $report): void
{
    app()->instance(InstallerPreflight::class, new class($report)
    {
        /** @param array<string, mixed> $report */
        public function __construct(private readonly array $report) {}

        /** @return array<string, mixed> */
        public function run(?InstallInputData $inputData = null): array
        {
            return $this->report;
        }
    });
}

it('starts queued and browser-step runs behind typed mode results', function (): void {
    Queue::fake();
    config(['cache.default' => 'array', 'queue.default' => 'database']);

    bindInstallerRunPreflight(installerPreflightReport());

    $action = resolve(StartInstallerRunAction::class);
    $queuedInstallId = '11111111-1111-4111-a111-111111111111';
    $browserInstallId = '22222222-2222-4222-a222-222222222222';

    $queued = $action->handle($queuedInstallId, installerRunInput(), InstallerRunMode::Queued);
    $browser = $action->handle($browserInstallId, installerRunInput(), InstallerRunMode::BrowserSteps);

    expect($queued)->toBeInstanceOf(InstallerRunStartData::class)
        ->and($queued->mode)->toBe(InstallerRunMode::Queued)
        ->and($queued->status)->toBe('queued')
        ->and($browser)->toBeInstanceOf(InstallerRunStartData::class)
        ->and($browser->mode)->toBe(InstallerRunMode::BrowserSteps)
        ->and($browser->status)->toBe('pending')
        ->and($browser->plan)->not->toBeEmpty()
        ->and($browser->nextStep)->toBe($browser->plan[0]['key'])
        ->and(resolve(InstallerSessionRepository::class)->preflightReport($browserInstallId))
        ->toBe(installerPreflightReport());

    Queue::assertPushed(RunCapellInstallJob::class, fn (RunCapellInstallJob $job): bool => $job->uniqueId() === $queuedInstallId);
});

it('runs synchronous starts and records their success summary', function (): void {
    config(['cache.default' => 'array']);

    RunInstallAction::mock()
        ->shouldReceive('handle')
        ->once();

    $installId = '21212121-2121-4121-a121-212121212121';
    $result = resolve(StartInstallerRunAction::class)->handle(
        $installId,
        installerRunInput(),
        InstallerRunMode::Synchronous,
    );
    $sessions = resolve(InstallerSessionRepository::class);

    expect($result)->toBeInstanceOf(InstallerRunStartData::class)
        ->and($result->mode)->toBe(InstallerRunMode::Synchronous)
        ->and($result->completed)->toBeTrue()
        ->and($result->status)->toBe('complete')
        ->and($sessions->hasSuccessSummary($installId))->toBeTrue()
        ->and($sessions->pullSuccessSummary($installId))->toMatchArray([
            'primaryAdmin' => null,
            'roleUsersCreated' => false,
        ]);
});

it('does not clear a foreign lock when a synchronous start fails', function (): void {
    config(['cache.default' => 'array']);

    $installId = '23232323-2323-4323-a323-232323232323';
    $foreignInstallId = '24242424-2424-4424-a424-242424242424';
    $sessions = resolve(InstallerSessionRepository::class);

    RunInstallAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (
            InstallInputData $inputData,
            ProgressReporter $reporter,
        ) use ($sessions, $foreignInstallId): never {
            $sessions->putStatus($foreignInstallId, 'running');
            $sessions->lock($foreignInstallId);

            throw new RuntimeException('Synchronous install failed');
        });

    $result = resolve(StartInstallerRunAction::class)->handle(
        $installId,
        installerRunInput(),
        InstallerRunMode::Synchronous,
    );

    expect($result->completed)->toBeFalse()
        ->and($result->status)->toBe('failed')
        ->and($sessions->activeInstallId())->toBe($foreignInstallId);
});

it('returns typed replay and out-of-sequence step results without executing a step', function (): void {
    config(['cache.default' => 'array']);

    $installId = '33333333-3333-4333-a333-333333333333';
    $plan = [
        ['key' => 'already-complete', 'label' => 'Already complete'],
        ['key' => 'expected-next', 'label' => 'Expected next'],
    ];
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: $plan,
        installStatus: 'running',
        firstStepKey: 'expected-next',
        preflight: installerPreflightReport(),
    );
    $sessions->recordCompletedStep($installId, 'already-complete', 'expected-next');

    $action = resolve(AdvanceInstallerRunAction::class);
    $replay = $action->handle($installId, 'already-complete');
    $outOfSequence = $action->handle($installId, 'not-started');

    expect($replay)->toBeInstanceOf(InstallerRunStepData::class)
        ->and($replay->code)->toBe(InstallerRunStepResultCode::Running)
        ->and($replay->nextStep)->toBe('expected-next')
        ->and($outOfSequence)->toBeInstanceOf(InstallerRunStepData::class)
        ->and($outOfSequence->code)->toBe(InstallerRunStepResultCode::OutOfSequence)
        ->and($outOfSequence->expectedStep)->toBe('expected-next')
        ->and($sessions->completedSteps($installId))->toBe(['already-complete']);
});

it('advances a passing preflight step and records its report', function (): void {
    config(['cache.default' => 'array']);

    $installId = '34343434-3434-4434-a434-343434343434';
    $plan = [
        ['key' => InstallPlan::STEP_PREFLIGHT_CHECKS, 'label' => 'Preflight checks'],
        ['key' => InstallPlan::STEP_PREPARE_ENVIRONMENT, 'label' => 'Prepare environment'],
    ];
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: $plan,
        installStatus: 'pending',
        firstStepKey: InstallPlan::STEP_PREFLIGHT_CHECKS,
        preflight: [],
    );
    bindInstallerRunPreflight(installerPreflightReport());

    $result = resolve(AdvanceInstallerRunAction::class)->handle(
        $installId,
        InstallPlan::STEP_PREFLIGHT_CHECKS,
    );

    expect($result->code)->toBe(InstallerRunStepResultCode::Running)
        ->and($result->nextStep)->toBe(InstallPlan::STEP_PREPARE_ENVIRONMENT)
        ->and($result->preflight)->toBe(installerPreflightReport())
        ->and($sessions->completedSteps($installId))->toBe([InstallPlan::STEP_PREFLIGHT_CHECKS])
        ->and($sessions->preflightReport($installId))->toBe(installerPreflightReport());
});

it('returns a typed preflight failure without clearing a foreign lock', function (): void {
    config(['cache.default' => 'array']);

    $installId = '35353535-3535-4535-a535-353535353535';
    $foreignInstallId = '36363636-3636-4636-a636-363636363636';
    $plan = [['key' => InstallPlan::STEP_PREFLIGHT_CHECKS, 'label' => 'Preflight checks']];
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: $plan,
        installStatus: 'pending',
        firstStepKey: InstallPlan::STEP_PREFLIGHT_CHECKS,
        preflight: [],
    );
    $sessions->putStatus($foreignInstallId, 'running');
    $sessions->lock($foreignInstallId);

    $failedPreflight = installerPreflightReport();
    $failedPreflight['status'] = 'fail';
    $failedPreflight['checks'] = [[
        'key' => 'cache',
        'label' => 'Cache',
        'status' => 'fail',
        'severity' => 'blocking',
        'message' => 'Cache unavailable',
        'remediation' => 'Configure a shared cache.',
    ]];
    bindInstallerRunPreflight($failedPreflight);

    $result = resolve(AdvanceInstallerRunAction::class)->handle(
        $installId,
        InstallPlan::STEP_PREFLIGHT_CHECKS,
    );

    expect($result->code)->toBe(InstallerRunStepResultCode::PreflightFailed)
        ->and($result->preflight)->toBe($failedPreflight)
        ->and($result->remediation)->toBe('Configure a shared cache.')
        ->and($sessions->activeInstallId())->toBe($foreignInstallId);
});

it('advances a successful installer step', function (): void {
    config(['cache.default' => 'array']);

    $installId = '37373737-3737-4737-a737-373737373737';
    $plan = [
        ['key' => InstallPlan::STEP_PREPARE_ENVIRONMENT, 'label' => 'Prepare environment'],
        ['key' => InstallPlan::STEP_CLEAR_CACHES, 'label' => 'Clear caches'],
    ];
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: $plan,
        installStatus: 'running',
        firstStepKey: InstallPlan::STEP_PREPARE_ENVIRONMENT,
        preflight: installerPreflightReport(),
    );
    RunInstallStepAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andReturnNull();

    $result = resolve(AdvanceInstallerRunAction::class)->handle(
        $installId,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
    );

    expect($result->code)->toBe(InstallerRunStepResultCode::Running)
        ->and($result->nextStep)->toBe(InstallPlan::STEP_CLEAR_CACHES)
        ->and($sessions->completedSteps($installId))->toBe([InstallPlan::STEP_PREPARE_ENVIRONMENT]);
});

it('returns a typed execution failure without clearing a foreign lock', function (): void {
    config(['cache.default' => 'array']);

    $installId = '38383838-3838-4838-a838-383838383838';
    $foreignInstallId = '39393939-3939-4939-a939-393939393939';
    $plan = [['key' => InstallPlan::STEP_PREPARE_ENVIRONMENT, 'label' => 'Prepare environment']];
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: $plan,
        installStatus: 'running',
        firstStepKey: InstallPlan::STEP_PREPARE_ENVIRONMENT,
        preflight: installerPreflightReport(),
    );
    $sessions->putStatus($foreignInstallId, 'running');
    $sessions->lock($foreignInstallId);
    RunInstallStepAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('Step exploded'));

    $result = resolve(AdvanceInstallerRunAction::class)->handle(
        $installId,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
    );

    expect($result->code)->toBe(InstallerRunStepResultCode::ExecutionFailed)
        ->and($result->exceptionClass)->toBe(RuntimeException::class)
        ->and($result->exceptionMessage)->toBe('Step exploded')
        ->and($sessions->activeInstallId())->toBe($foreignInstallId);
});

it('clears its owned lock when an installer step fails', function (): void {
    config(['cache.default' => 'array']);

    $installId = '40404040-4040-4040-a040-404040404040';
    $plan = [['key' => InstallPlan::STEP_PREPARE_ENVIRONMENT, 'label' => 'Prepare environment']];
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: $plan,
        installStatus: 'running',
        firstStepKey: InstallPlan::STEP_PREPARE_ENVIRONMENT,
        preflight: installerPreflightReport(),
    );
    RunInstallStepAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('Owned step failed'));

    $result = resolve(AdvanceInstallerRunAction::class)->handle(
        $installId,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
    );

    expect($result->code)->toBe(InstallerRunStepResultCode::ExecutionFailed)
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBeNull();
});

it('completes the final step and preserves a foreign lock', function (): void {
    config(['cache.default' => 'array']);

    $installId = '41414141-4141-4141-a141-414141414141';
    $foreignInstallId = '42424242-4242-4242-a242-424242424242';
    $plan = [['key' => InstallPlan::STEP_PREPARE_ENVIRONMENT, 'label' => 'Prepare environment']];
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: $plan,
        installStatus: 'running',
        firstStepKey: InstallPlan::STEP_PREPARE_ENVIRONMENT,
        preflight: installerPreflightReport(),
    );
    $sessions->putStatus($foreignInstallId, 'running');
    $sessions->lock($foreignInstallId);
    RunInstallStepAction::mock()
        ->shouldReceive('handle')
        ->once()
        ->andReturnNull();

    $result = resolve(AdvanceInstallerRunAction::class)->handle(
        $installId,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
    );

    expect($result->code)->toBe(InstallerRunStepResultCode::Complete)
        ->and($result->nextStep)->toBeNull()
        ->and($sessions->hasSuccessSummary($installId))->toBeTrue()
        ->and($sessions->activeInstallId())->toBe($foreignInstallId);
});

it('replays an already completed run without clearing a foreign lock', function (): void {
    config(['cache.default' => 'array']);

    $installId = '46464646-4646-4646-a646-464646464646';
    $foreignInstallId = '47474747-4747-4747-a747-474747474747';
    $plan = [['key' => InstallPlan::STEP_PREPARE_ENVIRONMENT, 'label' => 'Prepare environment']];
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: $plan,
        installStatus: 'complete',
        firstStepKey: InstallPlan::STEP_PREPARE_ENVIRONMENT,
        preflight: installerPreflightReport(),
    );
    $sessions->putStatus($foreignInstallId, 'running');
    $sessions->lock($foreignInstallId);

    $result = resolve(AdvanceInstallerRunAction::class)->handle(
        $installId,
        InstallPlan::STEP_PREPARE_ENVIRONMENT,
    );

    expect($result->code)->toBe(InstallerRunStepResultCode::Complete)
        ->and($sessions->activeInstallId())->toBe($foreignInstallId);
});

it('reads terminal progress and builds a typed diagnostic report', function (): void {
    config(['cache.default' => 'array']);

    $installId = '44444444-4444-4444-a444-444444444444';
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->startStepInstallSession(
        installId: $installId,
        inputData: installerRunInput(),
        plan: [['key' => 'preflight-checks', 'label' => 'Preflight checks']],
        installStatus: 'failed',
        firstStepKey: 'preflight-checks',
        preflight: installerPreflightReport(),
    );
    $sessions->putSuccessSummary($installId, ['primaryAdmin' => null, 'roleUsersCreated' => false]);
    Cache::put(
        $sessions->key($installId, 'output'),
        json_encode(['message' => 'Install failed'], JSON_THROW_ON_ERROR),
    );

    $progress = resolve(ReadInstallerRunProgressAction::class)->handle($installId);
    $report = resolve(BuildInstallerRunReportAction::class)->handle($installId);

    expect($progress)->toBeInstanceOf(InstallerRunProgressData::class)
        ->and($progress->status)->toBe('failed')
        ->and($progress->shouldRedirectToSuccess)->toBeFalse()
        ->and($sessions->hasActiveInstallLock())->toBeFalse()
        ->and($sessions->hasSuccessSummary($installId))->toBeFalse()
        ->and($report)->toBeInstanceOf(InstallerRunReportData::class)
        ->and($report->toPayload())->toMatchArray([
            'installId' => $installId,
            'status' => 'failed',
            'environment' => ['php' => PHP_VERSION],
            'preflight' => installerPreflightReport(),
            'lines' => [(object) ['message' => 'Install failed']],
        ]);
});

it('does not clear a foreign lock when reading stale terminal progress', function (): void {
    config(['cache.default' => 'array']);

    $installId = '43434343-4343-4343-a343-434343434343';
    $foreignInstallId = '45454545-4545-4545-a545-454545454545';
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->putStatus($installId, 'failed');
    $sessions->putStatus($foreignInstallId, 'running');
    $sessions->lock($foreignInstallId);

    $progress = resolve(ReadInstallerRunProgressAction::class)->handle($installId);

    expect($progress->status)->toBe('failed')
        ->and($sessions->activeInstallId())->toBe($foreignInstallId);
});

it('cancels one run without clearing another run lock', function (): void {
    config(['cache.default' => 'array']);

    $cancelledInstallId = '55555555-5555-4555-a555-555555555555';
    $activeInstallId = '66666666-6666-4666-a666-666666666666';
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->putStatus($cancelledInstallId, 'running');
    $sessions->putStatus($activeInstallId, 'running');
    $sessions->lock($activeInstallId);

    resolve(CancelInstallerRunAction::class)->handle($cancelledInstallId);

    expect($sessions->hasInstallSessionState($cancelledInstallId))->toBeFalse()
        ->and($sessions->activeInstallId())->toBe($activeInstallId);
});
