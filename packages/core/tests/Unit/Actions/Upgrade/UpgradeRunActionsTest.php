<?php

declare(strict_types=1);

use Capell\Core\Actions\Upgrade\ClaimQueuedUpgradeRunAction;
use Capell\Core\Actions\Upgrade\CreateUpgradeRunAction;
use Capell\Core\Actions\Upgrade\MarkUpgradeRunFinishedAction;
use Capell\Core\Actions\Upgrade\RecordUpgradeRunEventAction;
use Capell\Core\Actions\Upgrade\RedactUpgradeRunContextAction;
use Capell\Core\Data\Upgrade\UpgradeReadinessReportData;
use Capell\Core\Data\UpgradeRunOptions;
use Capell\Core\Enums\Upgrade\UpgradeReadinessResult;
use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Models\UpgradeRun;

it('creates queued runs and records an initial event', function (): void {
    $run = CreateUpgradeRunAction::run(
        options: new UpgradeRunOptions(dryRun: true, force: true, noClearCache: true),
        readiness: new UpgradeReadinessReportData(UpgradeReadinessResult::Ready, []),
        status: UpgradeRunStatus::Queued,
        manualCommands: ['php artisan capell:upgrade --force --no-clear-cache --dry-run'],
    );

    expect($run)->toBeInstanceOf(UpgradeRun::class)
        ->and($run->status)->toBe(UpgradeRunStatus::Queued)
        ->and($run->dry_run)->toBeTrue()
        ->and($run->events()->count())->toBe(1);
});

it('claims queued runs atomically and ignores already claimed runs', function (): void {
    $run = CreateUpgradeRunAction::run(
        options: new UpgradeRunOptions,
        readiness: new UpgradeReadinessReportData(UpgradeReadinessResult::Ready, []),
        status: UpgradeRunStatus::Queued,
        manualCommands: [],
    );

    $claimed = ClaimQueuedUpgradeRunAction::run((int) $run->getKey());
    $secondClaim = ClaimQueuedUpgradeRunAction::run((int) $run->getKey());

    expect($claimed)->toBeInstanceOf(UpgradeRun::class)
        ->and($claimed?->status)->toBe(UpgradeRunStatus::Running)
        ->and($secondClaim)->toBeNull();
});

it('records redacted events and output excerpts', function (): void {
    $run = CreateUpgradeRunAction::run(
        options: new UpgradeRunOptions,
        readiness: new UpgradeReadinessReportData(UpgradeReadinessResult::Ready, []),
        status: UpgradeRunStatus::Queued,
        manualCommands: [],
    );

    $event = RecordUpgradeRunEventAction::run(
        run: $run,
        level: UpgradeRunEventLevel::Error,
        message: 'Failed with token=secret-value',
        stage: UpgradeStage::Failed,
        context: ['authorization' => 'Bearer abc123'],
        outputExcerpt: 'COMPOSER_AUTH={"github-oauth":{"github.com":"token"}}',
    );

    expect($event->message)->toBe('Failed with token= [redacted]')
        ->and($event->context['authorization'])->toBe('[redacted]')
        ->and($event->output_excerpt)->toContain('COMPOSER_AUTH=[redacted]');
});

it('redacts composer auth credentials embedded in upgrade diagnostic strings', function (): void {
    $redacted = RedactUpgradeRunContextAction::run([
        'output' => 'Bearer abc+/= password=hunter2 {"github-oauth":{"github.com":"ghp_secret_token"},"http-basic":{"repo.example.com":{"username":"token","password":"basic_secret"}}}',
        'nested' => [
            'api_key' => 'plain-secret-value',
        ],
    ]);

    expect($redacted['output'])
        ->toContain('Bearer [redacted]')
        ->toContain('password= [redacted]')
        ->not->toContain('abc+/=')
        ->not->toContain('hunter2')
        ->not->toContain('ghp_secret_token')
        ->not->toContain('basic_secret')
        ->and($redacted['nested'])->toBe([
            'api_key' => '[redacted]',
        ]);
});

it('redacts URL userinfo and standalone GitHub tokens', function (): void {
    $token = 'ghp_' . str_repeat('a', 36);
    $redacted = RedactUpgradeRunContextAction::run([
        'output' => "Clone https://deploy-user:deploy-secret@example.com/repo.git with {$token}",
    ]);

    expect($redacted['output'])
        ->not->toContain('deploy-user')
        ->not->toContain('deploy-secret')
        ->not->toContain($token)
        ->toContain('https://[redacted]@example.com/repo.git');
});

it('marks runs as terminal without overwriting terminal state', function (): void {
    $run = CreateUpgradeRunAction::run(
        options: new UpgradeRunOptions,
        readiness: new UpgradeReadinessReportData(UpgradeReadinessResult::Ready, []),
        status: UpgradeRunStatus::Queued,
        manualCommands: [],
    );

    $finished = MarkUpgradeRunFinishedAction::run(
        run: $run,
        status: UpgradeRunStatus::Failed,
        message: 'First failure',
    );
    $again = MarkUpgradeRunFinishedAction::run(
        run: $finished,
        status: UpgradeRunStatus::Succeeded,
        message: 'Should not overwrite',
    );

    expect($again->refresh()->status)->toBe(UpgradeRunStatus::Failed)
        ->and($again->failure_reason)->toBe('First failure');
});

it('redacts terminal failure reasons before persisting them', function (): void {
    $run = CreateUpgradeRunAction::run(
        options: new UpgradeRunOptions,
        readiness: new UpgradeReadinessReportData(UpgradeReadinessResult::Ready, []),
        status: UpgradeRunStatus::Queued,
        manualCommands: [],
    );

    $finished = MarkUpgradeRunFinishedAction::run(
        run: $run,
        status: UpgradeRunStatus::Failed,
        message: 'Failure with password=super-secret and Bearer abc123',
    );

    expect($finished->failure_reason)->toBe('Failure with password= [redacted] and Bearer [redacted]')
        ->and($finished->events()->latest('id')->first()?->message)->toBe('Failure with password= [redacted] and Bearer [redacted]');
});
