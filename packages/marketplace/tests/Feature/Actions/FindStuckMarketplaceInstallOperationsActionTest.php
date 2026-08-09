<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\FindStuckMarketplaceInstallOperationsAction;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;

it('finds a queued operation that no worker has picked up', function (): void {
    $abandoned = marketplaceStuckAttempt([
        'composer_name' => 'capell-app/abandoned-suite',
        'extension_slug' => 'abandoned-suite',
        'queued_at' => now()->subSeconds(600),
    ]);

    expect(FindStuckMarketplaceInstallOperationsAction::run()->modelKeys())
        ->toBe([$abandoned->getKey()]);
});

it('leaves a freshly queued operation alone', function (): void {
    marketplaceStuckAttempt([
        'composer_name' => 'capell-app/fresh-suite',
        'extension_slug' => 'fresh-suite',
        'queued_at' => now()->subSeconds(10),
    ]);

    expect(FindStuckMarketplaceInstallOperationsAction::run())->toBeEmpty();
});

it('reads the queued staleness threshold from configuration', function (): void {
    config()->set('capell-marketplace.marketplace.queued_stale_after_seconds', 30);

    marketplaceStuckAttempt([
        'composer_name' => 'capell-app/borderline-suite',
        'extension_slug' => 'borderline-suite',
        'queued_at' => now()->subSeconds(45),
    ]);

    expect(FindStuckMarketplaceInstallOperationsAction::queuedStaleAfterSeconds())->toBe(30)
        ->and(FindStuckMarketplaceInstallOperationsAction::run())->toHaveCount(1);
});

it('falls back to the creation time when an operation was never stamped as queued', function (): void {
    $attempt = marketplaceStuckAttempt([
        'composer_name' => 'capell-app/unstamped-suite',
        'extension_slug' => 'unstamped-suite',
        'queued_at' => null,
    ]);

    $attempt->forceFill(['created_at' => now()->subSeconds(600)])->save();

    expect(FindStuckMarketplaceInstallOperationsAction::run()->modelKeys())
        ->toBe([$attempt->getKey()]);
});

it('still finds running and cancel-requested operations whose heartbeat stopped', function (): void {
    $running = marketplaceStuckAttempt([
        'composer_name' => 'capell-app/running-suite',
        'extension_slug' => 'running-suite',
        'status' => MarketplaceInstallIntentStatus::Running,
        'started_at' => now()->subHour(),
        'heartbeat_at' => now()->subHour(),
    ]);

    $cancelRequested = marketplaceStuckAttempt([
        'composer_name' => 'capell-app/cancel-requested-suite',
        'extension_slug' => 'cancel-requested-suite',
        'status' => MarketplaceInstallIntentStatus::CancelRequested,
        'started_at' => now()->subHours(2),
        'heartbeat_at' => null,
    ]);

    marketplaceStuckAttempt([
        'composer_name' => 'capell-app/healthy-running-suite',
        'extension_slug' => 'healthy-running-suite',
        'status' => MarketplaceInstallIntentStatus::Running,
        'started_at' => now()->subHour(),
        'heartbeat_at' => now(),
    ]);

    expect(FindStuckMarketplaceInstallOperationsAction::run()->modelKeys())
        ->toEqualCanonicalizing([$running->getKey(), $cancelRequested->getKey()]);
});

it('does not report finished operations', function (): void {
    marketplaceStuckAttempt([
        'composer_name' => 'capell-app/succeeded-suite',
        'extension_slug' => 'succeeded-suite',
        'status' => MarketplaceInstallIntentStatus::Succeeded,
        'queued_at' => now()->subDay(),
        'started_at' => now()->subDay(),
        'completed_at' => now()->subDay(),
    ]);

    expect(FindStuckMarketplaceInstallOperationsAction::run())->toBeEmpty();
});

function marketplaceStuckAttempt(array $overrides = []): MarketplaceInstallAttempt
{
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/stuck-suite',
        'extension_slug' => 'stuck-suite',
        'extension_name' => 'Stuck Suite',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'composer_command' => 'composer require capell-app/stuck-suite:^1.0',
        'version_constraint' => '^1.0',
        'queued_at' => now(),
        ...$overrides,
    ]);
}
