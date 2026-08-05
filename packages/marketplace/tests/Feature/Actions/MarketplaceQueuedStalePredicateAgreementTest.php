<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\FindStuckMarketplaceInstallOperationsAction;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;

/**
 * Queued staleness is asked twice: once in SQL, when the doctor sweeps for
 * stuck operations, and once in memory, when the operations widget polls or the
 * job decides whether anything ever claimed the attempt. The threshold is
 * shared, but the queued_at/created_at fallback and the comparison itself are
 * two pieces of code, so this pins them to the same answer.
 */
it('answers queued staleness the same way in SQL and in memory', function (array $overrides, bool $expectedStale): void {
    $attempt = MarketplaceInstallAttempt::query()->create([
        'composer_name' => 'capell-app/queued-stale-agreement',
        'extension_slug' => 'queued-stale-agreement',
        'extension_name' => 'Queued Stale Agreement',
        'kind' => 'tool',
        'status' => MarketplaceInstallIntentStatus::Queued,
        'composer_command' => 'composer require capell-app/queued-stale-agreement:^1.0',
        'version_constraint' => '^1.0',
    ]);

    // created_at is guarded, and the never-dispatched case is exactly the one
    // where it is the only age the attempt has.
    $attempt->forceFill($overrides)->save();

    $foundBySql = FindStuckMarketplaceInstallOperationsAction::run()
        ->contains(fn (MarketplaceInstallAttempt $stuck): bool => (int) $stuck->getKey() === (int) $attempt->getKey());

    expect(FindStuckMarketplaceInstallOperationsAction::isQueuedStale($attempt))->toBe($expectedStale)
        ->and($foundBySql)->toBe($expectedStale);
})->with([
    'queued long ago' => [
        fn (): array => ['queued_at' => now()->subSeconds(FindStuckMarketplaceInstallOperationsAction::queuedStaleAfterSeconds() + 60)],
        true,
    ],
    'queued just now' => [
        fn (): array => ['queued_at' => now()],
        false,
    ],
    'never dispatched, created long ago' => [
        fn (): array => [
            'queued_at' => null,
            'created_at' => now()->subSeconds(FindStuckMarketplaceInstallOperationsAction::queuedStaleAfterSeconds() + 60),
        ],
        true,
    ],
    'never dispatched, created just now' => [
        fn (): array => ['queued_at' => null],
        false,
    ],
]);
