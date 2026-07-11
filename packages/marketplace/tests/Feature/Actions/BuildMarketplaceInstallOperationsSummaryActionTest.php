<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\BuildMarketplaceInstallOperationsSummaryAction;
use Capell\Marketplace\Actions\ListMarketplaceInstallOperationsAction;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('summarizes marketplace install operations once per request scope', function (): void {
    marketplaceSummaryAttempt('capell-app/queued-suite', MarketplaceInstallIntentStatus::Queued);
    marketplaceSummaryAttempt('capell-app/running-suite', MarketplaceInstallIntentStatus::Running);
    marketplaceSummaryAttempt('capell-app/failed-suite', MarketplaceInstallIntentStatus::Failed);
    marketplaceSummaryAttempt('capell-app/deployment-failed-suite', MarketplaceInstallIntentStatus::Succeeded, [
        'deployment' => [
            'status' => 'failed',
            'failure_reason' => 'Deployment unavailable.',
        ],
    ]);
    marketplaceSummaryAttempt('capell-app/resolved-suite', MarketplaceInstallIntentStatus::Failed, ['resolved_at' => now()]);

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'marketplace_install_attempts')) {
            $queries++;
        }
    });

    $summary = BuildMarketplaceInstallOperationsSummaryAction::run();
    $sameSummary = BuildMarketplaceInstallOperationsSummaryAction::run();

    expect($summary)->toBe($sameSummary)
        ->and($summary->operations)->toHaveCount(4)
        ->and($summary->operationsCount)->toBe(4)
        ->and($summary->activeCount)->toBe(2)
        ->and($summary->attentionCount)->toBe(2)
        ->and($queries)->toBe(4);
});

it('reuses marketplace install operation summary for legacy list helpers', function (): void {
    marketplaceSummaryAttempt('capell-app/queued-suite', MarketplaceInstallIntentStatus::Queued);
    marketplaceSummaryAttempt('capell-app/failed-suite', MarketplaceInstallIntentStatus::Failed);

    $summary = BuildMarketplaceInstallOperationsSummaryAction::run();
    $operations = ListMarketplaceInstallOperationsAction::run();

    expect($operations)->toBe($summary->operations)
        ->and((new ListMarketplaceInstallOperationsAction)->count())->toBe(2)
        ->and((new ListMarketplaceInstallOperationsAction)->activeCount())->toBe(1)
        ->and((new ListMarketplaceInstallOperationsAction)->count(attentionOnly: true))->toBe(1);
});

function marketplaceSummaryAttempt(
    string $composerName,
    MarketplaceInstallIntentStatus $status,
    array $overrides = [],
): MarketplaceInstallAttempt {
    return MarketplaceInstallAttempt::query()->create([
        'composer_name' => $composerName,
        'extension_slug' => str($composerName)->after('/')->replace('-', '_')->toString(),
        'extension_name' => str($composerName)->after('/')->headline()->toString(),
        'kind' => 'tool',
        'status' => $status,
        'composer_command' => sprintf('composer require %s', $composerName),
        'queued_at' => now(),
        ...$overrides,
    ]);
}
