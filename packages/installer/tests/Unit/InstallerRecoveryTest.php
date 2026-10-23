<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Actions\Install\RunInstallStepAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\Install\RunInstallStepResultData;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Jobs\RunCapellInstallJob;
use Capell\Core\Support\Install\CacheProgressReporter;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Installer\Actions\AdvanceInstallerRunAction;
use Capell\Installer\Actions\BuildInstallerRunReportAction;
use Capell\Installer\Actions\StartInstallerRunAction;
use Capell\Installer\Enums\InstallerRunMode;
use Capell\Installer\Enums\InstallerRunStepResultCode;
use Capell\Installer\Support\InstallerSessionRepository;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

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

it('serialises a paused replacement decision with ownership publication', function (): void {
    $sessions = resolve(InstallerSessionRepository::class);
    $store = new class extends ArrayStore
    {
        public ?Closure $onRead = null;

        #[Override]
        public function get($key): mixed
        {
            $observed = parent::get($key);
            if ($this->onRead instanceof Closure) {
                ($this->onRead)($key);
            }

            return $observed;
        }
    };
    Cache::extend('ownership-probe', fn (): Repository => new Repository($store));
    config(['cache.default' => 'ownership-probe', 'cache.stores.ownership-probe' => ['driver' => 'ownership-probe']]);
    $interleaved = false;
    $probe = true;
    $store->onRead = function (string $key) use ($sessions, &$probe, &$interleaved): void {
        if ($probe && $key === InstallerSessionRepository::LOCK_KEY) {
            $probe = false;
            $contender = Cache::lock('capell.install.ownership', 30);
            if ($contender->get()) {
                $contender->release();
                $interleaved = true;
                $sessions->run('interleaved-old-run')->startQueued();
            }
        }

    };

    $sessions->run('paused-replacement')->startQueued();
    expect($interleaved)->toBeFalse()
        ->and($sessions->activeInstallId())->toBe('paused-replacement');
});

it('retains redacted diagnostics emitted after cancellation and repeated cancellation', function (): void {
    $id = 'late-diagnostics';
    $sessions = startRecoveryRun($id);
    $reporter = new CacheProgressReporter($id);
    $reporter->report('before cancellation');
    $sessions->run($id)->cancel();
    $reporter->error('after cancellation final diagnostic private-password');
    $sessions->run($id)->cancel();

    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    expect(json_encode($report))->toContain('before cancellation', 'after cancellation final diagnostic', '[redacted]')->not->toContain('private-password')
        ->and($sessions->input($id))->toBeNull()
        ->and($sessions->status($id))->toBe('cancelled');
});

it('retains the decisive failure after five hundred progress entries', function (): void {
    $id = 'failure-tail';
    $sessions = startRecoveryRun($id);
    $reporter = new CacheProgressReporter($id);
    for ($i = 0; $i < 500; $i++) {
        $reporter->report('progress ' . $i);
    }

    $reporter->error('decisive terminal failure');
    $sessions->run($id)->cancel();

    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    expect($report['lines'])->toHaveCount(501)
        ->and(json_encode($report))->toContain('decisive terminal failure');
});

it('retains the late browser exception without restoring executable state', function (): void {
    $id = 'late-browser-exception';
    $sessions = startRecoveryRun($id);
    RunInstallStepAction::mock()->shouldReceive('handle')->once()->andReturnUsing(function () use ($sessions, $id): never {
        $sessions->run($id)->cancel();
        throw new RuntimeException('late exception private-password');
    });

    $result = AdvanceInstallerRunAction::run($id, InstallPlan::STEP_PREPARE_ENVIRONMENT);
    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    expect($result->code)->toBe(InstallerRunStepResultCode::SessionNotFound)
        ->and(json_encode($report))->toContain('late exception')->not->toContain('private-password')
        ->and($sessions->input($id))->toBeNull()
        ->and($sessions->completedSteps($id))->toBe([]);
});

it('does not commit a superseded synchronous success or failure', function (bool $fail): void {
    $sessions = resolve(InstallerSessionRepository::class);
    RunInstallAction::mock()->shouldReceive('handle')->once()->andReturnUsing(function () use ($sessions, $fail): void {
        $sessions->run('sync-replacement')->startSynchronous();
        throw_if($fail, RuntimeException::class, 'late synchronous failure');
    });

    $result = StartInstallerRunAction::run('sync-old', recoveryInput(), InstallerRunMode::Synchronous);
    expect($result->completed)->toBeFalse()
        ->and($result->status)->toBe('cancelled')
        ->and($sessions->status('sync-old'))->toBe('cancelled')
        ->and($sessions->hasSuccessSummary('sync-old'))->toBeFalse()
        ->and($sessions->activeInstallId())->toBe('sync-replacement');
})->with([false, true]);

it('rejects direct terminal state writes by a cancelled or superseded run', function (string $method, bool $released): void {
    $sessions = startRecoveryRun('stale-direct-state');
    if ($released) {
        $sessions->run('stale-direct-state')->releaseLock();
    }

    $sessions->run('current-direct-state')->startSynchronous();
    $sessions->run('stale-direct-state')->{$method}();

    expect($sessions->status('stale-direct-state'))->toBe('cancelled')
        ->and($sessions->activeInstallId())->toBe('current-direct-state');
})->with(['markRunning', 'markComplete', 'markFailed'])->with([false, true]);

it('redacts queued input secrets before and after cancellation without caching executable input', function (): void {
    $id = 'queued-report-secrets';
    $sessions = resolve(InstallerSessionRepository::class);
    $sessions->run($id)->startQueued();
    RunInstallAction::mock()->shouldReceive('handle')->once()->andReturnUsing(function (InstallInputData $input, ProgressReporter $reporter) use ($sessions, $id): void {
        $reporter->report('before cancellation private-password');
        $sessions->run($id)->cancel();
        $reporter->error('late queued diagnostic private-password');
    });
    new RunCapellInstallJob(recoveryInput(), $id)->handle();

    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    expect(json_encode($report))->toContain('before cancellation', 'late queued diagnostic')->not->toContain('private-password')
        ->and($sessions->input($id))->toBeNull()
        ->and($sessions->status($id))->toBe('cancelled');
});

it('bounds late terminal output while retaining the final failure and reporting omissions', function (): void {
    $id = 'late-output-bound';
    $sessions = startRecoveryRun($id);
    $sessions->run($id)->cancel();
    $reporter = new CacheProgressReporter($id);
    for ($i = 0; $i < 300; $i++) {
        $reporter->report(str_repeat('progress ', 150));
    }

    $reporter->error('final bounded failure');

    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    $bytes = array_sum(array_map(fn (mixed $line): int => strlen(json_encode($line, JSON_THROW_ON_ERROR)) + 1, $report['lines']));
    expect($bytes)->toBeLessThanOrEqual(262144)
        ->and($report['diagnostics']['omittedOutputLines'])->toBeGreaterThan(0)
        ->and(json_encode(end($report['lines'])))->toContain('final bounded failure')
        ->and($sessions->input($id))->toBeNull();
});

it('fails closed for late output when a historical report has no redaction context', function (): void {
    $id = 'historical-redaction';
    $sessions = startRecoveryRun($id);
    $sessions->run($id)->cancel();
    $sessions->forget($sessions->key($id, 'report_redaction'));
    new CacheProgressReporter($id)->error('private-password late diagnostic');

    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    expect(json_encode($report))->toContain('Late diagnostic omitted: redaction context unavailable')->not->toContain('private-password');
});

it('retains a late queue failure callback without changing cancellation or replacement ownership', function (): void {
    $id = 'late-queue-callback';
    $sessions = startRecoveryRun($id);
    $sessions->run('queue-callback-replacement')->startSynchronous();
    new RunCapellInstallJob(recoveryInput(), $id)->failed(new RuntimeException('late queue timeout private-password'));

    $report = BuildInstallerRunReportAction::run($id)->toPayload();
    expect(json_encode($report))->toContain('late queue timeout')->not->toContain('private-password')
        ->and($sessions->status($id))->toBe('cancelled')
        ->and($sessions->activeInstallId())->toBe('queue-callback-replacement');
});

it('serialises stale owner cleanup with status publication', function (): void {
    $sessions = resolve(InstallerSessionRepository::class);
    $id = 'cleanup-status-publication';
    $store = new class extends ArrayStore
    {
        public ?Closure $onRead = null;

        #[Override]
        public function get($key): mixed
        {
            $observed = parent::get($key);
            if ($this->onRead instanceof Closure) {
                ($this->onRead)($key);
            }

            return $observed;
        }
    };
    Cache::extend('cleanup-probe', fn (): Repository => new Repository($store));
    config(['cache.default' => 'cleanup-probe', 'cache.stores.cleanup-probe' => ['driver' => 'cleanup-probe']]);
    Cache::put(InstallerSessionRepository::LOCK_KEY, ['installId' => $id]);
    $sessions->putStatus($id, 'failed');
    $probe = true;
    $interleaved = false;
    $store->onRead = function (string $key) use ($sessions, $id, &$probe, &$interleaved): void {
        if ($probe && $key === $sessions->key($id, 'status')) {
            $probe = false;
            $contender = Cache::lock('capell.install.ownership', 30);
            if ($contender->get()) {
                $interleaved = true;
                $sessions->putStatus($id, 'running');
                $contender->release();
            }
        }
    };

    expect($sessions->activeInstallId())->toBe($id)
        ->and($interleaved)->toBeTrue()
        ->and($sessions->status($id))->toBe('running')
        ->and(Cache::get(InstallerSessionRepository::LOCK_KEY))->toBe(['installId' => $id]);
});
