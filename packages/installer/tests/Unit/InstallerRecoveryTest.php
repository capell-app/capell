<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Data\Install\RunInstallStepResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Installer\Actions\AdvanceInstallerRunAction;
use Capell\Installer\Actions\BuildInstallerRunReportAction;
use Capell\Installer\Enums\InstallerRunStepResultCode;
use Capell\Installer\Support\InstallerSessionRepository;

function recoveryInput(): InstallInputData
{
    return new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false, newUser: new NewUserData('Admin', 'admin@example.com', 'private-password'));
}

function startRecoveryRun(string $id): InstallerSessionRepository
{
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->run($id)->startBrowserSteps(recoveryInput(), [
        ['key' => InstallPlan::STEP_PREPARE_ENVIRONMENT, 'label' => 'Prepare'],
        ['key' => InstallPlan::STEP_RESOLVE_USER, 'label' => 'User'],
    ], InstallPlan::STEP_PREPARE_ENVIRONMENT, ['environment' => ['original' => true], 'checks' => []]);

    return $sessions;
}

it('admits one executor for overlapping requests and replays a lost response', function (): void {
    $id = 'overlapping-requests';
    $sessions = startRecoveryRun($id);
    $calls = 0;
    $duplicate = null;
    RunInstallStepAction::mock()->shouldReceive('handle')->andReturnUsing(function () use ($id, &$calls, &$duplicate): RunInstallStepResultData {
        if (++$calls === 1) {
            $duplicate = AdvanceInstallerRunAction::run($id, InstallPlan::STEP_PREPARE_ENVIRONMENT);
        }

        return new RunInstallStepResultData(null, false);
    });

    $result = AdvanceInstallerRunAction::run($id, InstallPlan::STEP_PREPARE_ENVIRONMENT);
    $replayed = AdvanceInstallerRunAction::run($id, InstallPlan::STEP_PREPARE_ENVIRONMENT);

    expect($calls)->toBe(1)
        ->and($duplicate?->code->value)->toBe('in-progress')
        ->and($result->nextStep)->toBe(InstallPlan::STEP_RESOLVE_USER)
        ->and($replayed->nextStep)->toBe($result->nextStep)
        ->and($sessions->completedSteps($id))->toBe([InstallPlan::STEP_PREPARE_ENVIRONMENT]);
});

it('does not commit an executing step after cancellation or replacement', function (bool $replace, bool $fail): void {
    $id = 'cancel-during-execution';
    $sessions = startRecoveryRun($id);
    $afterCancel = null;
    RunInstallStepAction::mock()->shouldReceive('handle')->once()->andReturnUsing(function () use ($sessions, $id, $replace, $fail, &$afterCancel): RunInstallStepResultData {
        if ($replace) {
            $sessions->run('replacement')->startSynchronous();
        } else {
            $sessions->run($id)->cancel();
        }

        $afterCancel = AdvanceInstallerRunAction::run($id, InstallPlan::STEP_PREPARE_ENVIRONMENT);
        throw_if($fail, RuntimeException::class, 'Late step failure');

        return new RunInstallStepResultData(123, true);
    });

    AdvanceInstallerRunAction::run($id, InstallPlan::STEP_PREPARE_ENVIRONMENT);
    expect($afterCancel?->code)->toBe(InstallerRunStepResultCode::SessionNotFound)
        ->and($sessions->status($id))->toBe('cancelled')
        ->and($sessions->completedSteps($id))->toBe([])
        ->and($sessions->input($id))->toBeNull()
        ->and($sessions->resolvedUserId($id))->toBeNull()
        ->and($sessions->activeInstallId())->toBe($replace ? 'replacement' : null);
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('retains original redacted cancellation evidence without executable state', function (): void {
    $id = 'cancelled-evidence';
    $sessions = startRecoveryRun($id);
    $sessions->recordCompletedStep($id, InstallPlan::STEP_PREPARE_ENVIRONMENT, InstallPlan::STEP_RESOLVE_USER);
    $sessions->recordStepPeakMemory($id, InstallPlan::STEP_PREPARE_ENVIRONMENT, 1234);
    new CacheProgressReporter($id)->error('password=private-password diagnostic failure');
    $sessions->run($id)->cancel();

    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    expect($report['plan'])->toHaveCount(2)
        ->and($report['environment'])->toBe(['original' => true])
        ->and($report['diagnostics']['completedSteps'])->toBe([InstallPlan::STEP_PREPARE_ENVIRONMENT])
        ->and($report['diagnostics']['steps'][InstallPlan::STEP_PREPARE_ENVIRONMENT]['peakMemoryBytes'])->toBe(1234)
        ->and(json_encode($report))->toContain('diagnostic failure')->not->toContain('private-password')
        ->and($sessions->input($id))->toBeNull()
        ->and($sessions->plan($id))->toBe([])
        ->and(AdvanceInstallerRunAction::run($id, InstallPlan::STEP_RESOLVE_USER)->code)->toBe(InstallerRunStepResultCode::SessionNotFound);
});

it('purges obsolete executable input even when it cannot hydrate the current input DTO', function (): void {
    $id = 'obsolete-input';
    $sessions = startRecoveryRun($id);
    $sessions->put($sessions->key($id, 'input'), ['password' => 'obsolete-secret']);
    new CacheProgressReporter($id)->error('obsolete-secret original diagnostic');

    $sessions->run($id)->cancel();
    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    expect($sessions->input($id))->toBeNull()
        ->and($sessions->status($id))->toBe('cancelled')
        ->and($report['plan'])->toHaveCount(2)
        ->and(json_encode($report))->toContain('original diagnostic')->not->toContain('obsolete-secret');
});

it('retires a failed browser run when its retry meets a replacement owner', function (bool $replacementComplete): void {
    $id = 'failed-before-replacement';
    $sessions = startRecoveryRun($id);
    $calls = 0;
    RunInstallStepAction::mock()->shouldReceive('handle')->andReturnUsing(function () use (&$calls): never {
        $calls++;
        throw new RuntimeException('Failed step');
    });
    AdvanceInstallerRunAction::run($id, InstallPlan::STEP_PREPARE_ENVIRONMENT);
    $sessions->run('replacement-after-failure')->startSynchronous();
    if ($replacementComplete) {
        $sessions->run('replacement-after-failure')->markComplete();
        $sessions->run('replacement-after-failure')->releaseLock();
    }

    $retry = AdvanceInstallerRunAction::run($id, InstallPlan::STEP_PREPARE_ENVIRONMENT);

    expect($retry->code)->toBe(InstallerRunStepResultCode::SessionNotFound)
        ->and($calls)->toBe(1)
        ->and($sessions->input($id))->toBeNull()
        ->and($sessions->status($id))->toBe('cancelled')
        ->and($sessions->activeInstallId())->toBe($replacementComplete ? null : 'replacement-after-failure');
})->with([false, true]);
