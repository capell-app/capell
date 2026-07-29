<?php

declare(strict_types=1);

use Capell\Core\Data\InstallInputData;
use Capell\Core\Jobs\RunCapellInstallJob;
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

it('starts queued and browser-step runs behind typed mode results', function (): void {
    Queue::fake();
    config(['cache.default' => 'array', 'queue.default' => 'database']);

    app()->instance(InstallerPreflight::class, new class
    {
        /** @return array<string, mixed> */
        public function run(?InstallInputData $inputData = null): array
        {
            return installerPreflightReport();
        }
    });

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
        ->and($replay->status)->toBe('running')
        ->and($replay->nextStep)->toBe('expected-next')
        ->and($replay->statusCode)->toBe(200)
        ->and($outOfSequence)->toBeInstanceOf(InstallerRunStepData::class)
        ->and($outOfSequence->status)->toBe('failed')
        ->and($outOfSequence->expectedStep)->toBe('expected-next')
        ->and($outOfSequence->statusCode)->toBe(409)
        ->and($sessions->completedSteps($installId))->toBe(['already-complete']);
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
