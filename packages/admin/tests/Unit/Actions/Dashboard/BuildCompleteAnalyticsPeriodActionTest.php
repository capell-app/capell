<?php

declare(strict_types=1);

use Capell\Admin\Actions\Dashboard\BuildCompleteAnalyticsPeriodAction;
use Carbon\CarbonImmutable;

it('uses complete source-local days and an immediately preceding equal calendar period', function (): void {
    $period = resolve(BuildCompleteAnalyticsPeriodAction::class)->handle(CarbonImmutable::parse('2026-03-30T13:00:00Z'), 2, 'Europe/London');

    expect($period->start->toIso8601String())->toBe('2026-03-28T00:00:00+00:00')
        ->and($period->end->toIso8601String())->toBe('2026-03-30T00:00:00+01:00')
        ->and($period->previousStart->toIso8601String())->toBe('2026-03-26T00:00:00+00:00')
        ->and($period->previousEnd->equalTo($period->start))->toBeTrue();
});

it('rejects unbounded periods and invalid time zones', function (): void {
    $action = resolve(BuildCompleteAnalyticsPeriodAction::class);
    expect(fn () => $action->handle(CarbonImmutable::now(), 0, 'UTC'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $action->handle(CarbonImmutable::now(), 30, 'invalid'))->toThrow(InvalidArgumentException::class);
});
