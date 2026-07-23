<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('rejects malformed and future metric rollup days', function (string $day): void {
    artisanCommand('capell:metrics:rollup', ['--day' => $day])->assertFailed();
})->with(['2026-2-1', 'not-a-date', '2999-01-01']);

it('processes pending completed UTC days by default', function (): void {
    CarbonImmutable::setTestNow('2026-07-22 00:10:00 Pacific/Auckland');

    artisanCommand('capell:metrics:rollup')->assertSuccessful()->expectsOutputToContain('0 metric event row(s)');
});

it('schedules metric rollups at 00:20 UTC with overlap and server guards', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'capell:metrics:rollup'));

    expect($event)->not->toBeNull()
        ->and($event?->getExpression())->toBe('20 0 * * *')
        ->and($event?->timezone)->toBe('UTC')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});
