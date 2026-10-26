<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\ClaimMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FinalizeMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FindStuckMarketplaceInstallOperationsAction;
use Capell\Marketplace\Actions\QueryMarketplaceInstallProgressAction;
use Capell\Marketplace\Data\MarketplaceComposerResultData;
use Capell\Marketplace\Data\MarketplaceInstallProgressQueryData;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Enums\MarketplaceRuntimePropagationStatus;
use Capell\Marketplace\Jobs\AbstractMarketplaceOperationJob;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUninstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Tests\Support\RecordingMarketplaceComposerRunner;

it('records a bounded propagation window atomically with successful installation', function (): void {
    $this->freezeTime();
    $attempt = pendingRuntimePropagationAttempt();

    expect($attempt->context)->toEqual([
        'runtime_propagation' => 'pending',
        'runtime_propagation_started_at' => now()->getTimestamp(),
        'runtime_propagation_deadline_at' => now()->addMinutes(5)->getTimestamp(),
    ]);
    $progress = QueryMarketplaceInstallProgressAction::run(MarketplaceInstallProgressQueryData::forAttemptIds([$attempt->id]))->items[0];
    expect($progress->isActive())->toBeTrue()
        ->and($progress->runtimeRefreshRequired)->toBeFalse()
        ->and(FindStuckMarketplaceInstallOperationsAction::run())->toBeEmpty();
});

it('preserves successful installation but requires recovery after worker interruption', function (MarketplaceOperationType $operation): void {
    $attempt = pendingRuntimePropagationAttempt($operation);
    pendingRuntimePropagationJob($attempt->id, $operation)->failed(new RuntimeException('Worker interrupted after finalisation.'));

    expect($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(($attempt->context['runtime_propagation'] ?? null))->toBe('recovery_required');
    $progress = QueryMarketplaceInstallProgressAction::run(MarketplaceInstallProgressQueryData::forAttemptIds([$attempt->id]))->items[0];
    expect($progress->succeeded())->toBeTrue()
        ->and($progress->isActive())->toBeFalse()
        ->and($progress->runtimeRefreshRequired)->toBeTrue();
})->with([
    MarketplaceOperationType::Install,
    MarketplaceOperationType::Update,
    MarketplaceOperationType::Uninstall,
]);

it('discovers and expires orphaned pending propagation at its deadline', function (bool $legacy, bool $byComposerName, int $minutes): void {
    $this->freezeTime();
    $attempt = pendingRuntimePropagationAttempt();
    if ($legacy) {
        $attempt->update(['context' => ['runtime_propagation' => 'pending']]);
    }

    $this->travel($minutes)->minutes();

    expect(FindStuckMarketplaceInstallOperationsAction::run()->modelKeys())->toBe([$attempt->id]);
    $query = $byComposerName
        ? MarketplaceInstallProgressQueryData::forComposerNames([$attempt->composer_name])
        : MarketplaceInstallProgressQueryData::forAttemptIds([$attempt->id]);
    $progress = QueryMarketplaceInstallProgressAction::run($query)->items[0];
    expect($progress->succeeded())->toBeTrue()
        ->and($progress->isActive())->toBeFalse()
        ->and($progress->runtimeRefreshRequired)->toBeTrue()
        ->and($progress->runtimePropagationStatus)->toBe(MarketplaceRuntimePropagationStatus::RecoveryRequired)
        ->and(($attempt->refresh()->context['runtime_propagation'] ?? null))->toBe('recovery_required');
})->with([false, true])->with([false, true])->with([5, 120]);

it('recovers redelivery without claiming the successful attempt or replaying composer', function (MarketplaceOperationType $operation): void {
    $attempt = pendingRuntimePropagationAttempt($operation);
    $composer = new RecordingMarketplaceComposerRunner;
    pendingRuntimePropagationJob($attempt->id, $operation)->handle($composer);

    expect($composer->calls)->toBe([])
        ->and(ClaimMarketplaceInstallAttemptAction::run($attempt, 2, 5))->toBeNull()
        ->and($attempt->refresh()->status)->toBe(MarketplaceInstallIntentStatus::Succeeded)
        ->and(($attempt->context['runtime_propagation'] ?? null))->toBe('recovery_required');
})->with([
    MarketplaceOperationType::Install,
    MarketplaceOperationType::Update,
    MarketplaceOperationType::Uninstall,
]);

function pendingRuntimePropagationAttempt(MarketplaceOperationType $operation = MarketplaceOperationType::Install): MarketplaceInstallAttempt
{
    $attempt = MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/interrupted-propagation',
        'extension_slug' => 'interrupted-propagation',
        'extension_name' => 'Interrupted Propagation',
        'kind' => 'tool',
        'operation' => $operation,
        'status' => MarketplaceInstallIntentStatus::Running,
        'started_at' => now(),
    ]);

    return FinalizeMarketplaceInstallAttemptAction::run($attempt, new MarketplaceComposerResultData(0, '', ''));
}

function pendingRuntimePropagationJob(int $id, MarketplaceOperationType $operation): AbstractMarketplaceOperationJob
{
    return match ($operation) {
        MarketplaceOperationType::Install => new RunMarketplaceInstallAttemptJob($id),
        MarketplaceOperationType::Update => new RunMarketplaceUpdateAttemptJob($id),
        MarketplaceOperationType::Uninstall => new RunMarketplaceUninstallAttemptJob($id),
    };
}
