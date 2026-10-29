<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\ValidateDashboardReportingPeriodAction;
use Carbon\CarbonImmutable;

it('accepts complete current or retained source-local days across clock changes', function (): void {
    CarbonImmutable::setTestNow('2026-03-30 12:00:00 UTC');
    $action = resolve(ValidateDashboardReportingPeriodAction::class);

    expect($action->handle('2026-03-28', '2026-03-30', 'Europe/London', 2, 'available'))->toBeTrue()
        ->and($action->handle('2026-03-01', '2026-03-03', 'Europe/London', 2, 'stale'))->toBeTrue()
        ->and($action->handle('2026-03-01', '2026-03-03', 'Europe/London', 2, 'available'))->toBeFalse()
        ->and($action->handle('2026-03-30', '2026-04-01', 'Europe/London', 2, 'stale'))->toBeFalse();
    CarbonImmutable::setTestNow();
});

it('rejects missing, malformed and non-comparable reporting dates', function (): void {
    $action = resolve(ValidateDashboardReportingPeriodAction::class);

    expect($action->handle(null, '2026-03-03', 'UTC', 2, 'stale'))->toBeFalse()
        ->and($action->handle('2026-02-30', '2026-03-03', 'UTC', 2, 'stale'))->toBeFalse()
        ->and($action->handle('2026-03-01', '2026-03-04', 'UTC', 2, 'stale'))->toBeFalse()
        ->and($action->handle('2026-03-01', '2026-03-03', 'invalid', 2, 'stale'))->toBeFalse();
});
