<?php

declare(strict_types=1);

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Marketplace\Actions\BuildMarketplaceOperationsDoctorReportAction;
use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\FindStuckMarketplaceInstallOperationsAction;
use Capell\Marketplace\Actions\RetryMarketplaceInstallAttemptAction;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceOperationType;
use Capell\Marketplace\Jobs\RunMarketplaceInstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUninstallAttemptJob;
use Capell\Marketplace\Jobs\RunMarketplaceUpdateAttemptJob;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Capell\Marketplace\Support\MarketplaceOperationVocabulary;
use Illuminate\Support\Facades\Queue;

const PARITY_PACKAGE_NAME = 'capell-app/parity-package';

function parityAttempt(MarketplaceOperationType $operation, array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => PARITY_PACKAGE_NAME,
        'extension_slug' => 'parity-package',
        'extension_name' => 'Parity Package',
        'kind' => 'plugin',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'operation' => $operation,
        'version_constraint' => '^1.0',
        'queued_at' => now(),
        ...$overrides,
    ]);
}

function registerParityPackage(): void
{
    CapellCore::registerPackage(PARITY_PACKAGE_NAME, PackageTypeEnum::Plugin, version: '1.0.0');
    CapellCore::markPackageInstalled(PARITY_PACKAGE_NAME);
}

it('retries an operation as the operation it was, not as an install', function (MarketplaceOperationType $operation, string $expectedJob): void {
    Queue::fake();

    // An uninstall requires the package to be present; an install and an
    // update go through `composer require` and require it absent. The fixture
    // matches the operation's own precondition.
    if ($operation === MarketplaceOperationType::Uninstall) {
        registerParityPackage();
    }

    $failed = parityAttempt($operation, [
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Something went wrong.',
        'failure_stage' => MarketplaceInstallFailureStage::Composer->value,
        'uninstall_options' => ['delete_package' => true, 'delete_data' => true],
        'completed_at' => now(),
    ]);

    $retry = RetryMarketplaceInstallAttemptAction::run($failed);

    expect($retry->operation)->toBe($operation)
        // The options travel with the retry: a retry that dropped them would
        // silently perform a different uninstall from the one that failed.
        ->and(capellJsonKeysSorted($retry->uninstall_options))
        ->toBe(capellJsonKeysSorted(['delete_package' => true, 'delete_data' => true]));

    Queue::assertPushed($expectedJob);
})->with([
    'install' => [MarketplaceOperationType::Install, RunMarketplaceInstallAttemptJob::class],
    'update' => [MarketplaceOperationType::Update, RunMarketplaceUpdateAttemptJob::class],
    'uninstall' => [MarketplaceOperationType::Uninstall, RunMarketplaceUninstallAttemptJob::class],
]);

it('finds a stuck operation whatever it was doing', function (MarketplaceOperationType $operation): void {
    config()->set('capell-marketplace.marketplace.queued_stale_after_seconds', 60);

    parityAttempt($operation, ['queued_at' => now()->subMinutes(30)]);

    $stuck = FindStuckMarketplaceInstallOperationsAction::run();

    expect($stuck)->toHaveCount(1)
        ->and($stuck->first()->operation)->toBe($operation);
})->with([
    'install' => [MarketplaceOperationType::Install],
    'update' => [MarketplaceOperationType::Update],
    'uninstall' => [MarketplaceOperationType::Uninstall],
]);

it('counts an unresolved failure of any operation in the doctor report', function (MarketplaceOperationType $operation): void {
    parityAttempt($operation, [
        'status' => MarketplaceInstallIntentStatus::Failed,
        'failure_reason' => 'Something went wrong.',
        'completed_at' => now(),
    ]);

    $failedCheck = collect(BuildMarketplaceOperationsDoctorReportAction::run()->checks)
        ->firstOrFail(fn (DoctorCheckResultData $check): bool => $check->id === 'marketplace.operations.failed');

    expect($failedCheck->passed)->toBeFalse()
        ->and($failedCheck->evidence['count'] ?? null)->toBe(1);
})->with([
    'install' => [MarketplaceOperationType::Install],
    'update' => [MarketplaceOperationType::Update],
    'uninstall' => [MarketplaceOperationType::Uninstall],
]);

it('cancels a queued operation whatever it was doing', function (MarketplaceOperationType $operation): void {
    $attempt = CancelMarketplaceInstallAttemptAction::run(parityAttempt($operation));

    expect($attempt->status)->toBe(MarketplaceInstallIntentStatus::Cancelled);
})->with([
    'install' => [MarketplaceOperationType::Install],
    'update' => [MarketplaceOperationType::Update],
    'uninstall' => [MarketplaceOperationType::Uninstall],
]);

it('gives an operation its own words when it has them and the shared ones when it does not', function (): void {
    expect(MarketplaceOperationVocabulary::key(MarketplaceOperationType::Uninstall, 'timeline_created'))
        ->toBe('capell-marketplace::marketplace.operations.uninstall.timeline_created')
        ->and(MarketplaceOperationVocabulary::key(MarketplaceOperationType::Install, 'timeline_created'))
        ->toBe('capell-marketplace::marketplace.operations.timeline_created')
        // No uninstall override, so the shared sentence stands.
        ->and(MarketplaceOperationVocabulary::key(MarketplaceOperationType::Uninstall, 'timeline_snapshot_captured'))
        ->toBe('capell-marketplace::marketplace.operations.timeline_snapshot_captured');
});

it('writes the operation-specific sentence onto the timeline when a status changes', function (): void {
    $attempt = parityAttempt(MarketplaceOperationType::Uninstall);

    CancelMarketplaceInstallAttemptAction::run($attempt);

    expect($attempt->refresh()->events()
        ->where('message', MarketplaceOperationVocabulary::translate(MarketplaceOperationType::Uninstall, 'timeline_cancelled'))
        ->exists())->toBeTrue();
});
