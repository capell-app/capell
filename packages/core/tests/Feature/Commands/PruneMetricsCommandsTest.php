<?php

declare(strict_types=1);

use Capell\Core\Actions\Activity\PruneActivityBucketsAction;
use Capell\Core\Actions\Metrics\PruneMetricDailyRollupsAction;

it('runs activity pruning in pretend mode without changing empty storage', function (): void {
    artisanCommand('capell:activity:prune', ['--days' => 1, '--pretend' => true])->assertSuccessful();

    expect(resolve(PruneActivityBucketsAction::class)->execute(days: 1, pretend: true))->toBe(0);
});

it('runs metric rollup pruning in pretend and delete modes for empty storage', function (): void {
    artisanCommand('capell:metrics:prune', ['--days' => 30, '--pretend' => true])->assertSuccessful();
    artisanCommand('capell:metrics:prune', ['--days' => 30])->assertSuccessful();

    expect(resolve(PruneMetricDailyRollupsAction::class)->execute(days: 30, pretend: true))->toBe(0)
        ->and(resolve(PruneMetricDailyRollupsAction::class)->execute(days: 30))->toBe(0);
});
