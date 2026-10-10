<?php

declare(strict_types=1);

use Capell\Tests\Support\BackupScheduleOptions;
use Capell\Tests\Support\ConfiguresBackupSchedule;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

pest()->use(ConfiguresBackupSchedule::class)->in(__FILE__);

function registeredBackupPruneSchedule(): ?Event
{
    $schedule = resolve(Schedule::class);

    return collect($schedule->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'capell:backup:prune'));
}

afterEach(function (): void {
    BackupScheduleOptions::$enabled = false;
    BackupScheduleOptions::$cron = '0 3 * * 1';
});

it('keeps destructive backup pruning unscheduled by default', function (): void {
    BackupScheduleOptions::$enabled = false;
    $this->refreshApplication();
    expect(registeredBackupPruneSchedule())->toBeNull();
});

it('schedules forced backup pruning with overlap and server guards when enabled', function (): void {
    BackupScheduleOptions::$enabled = true;
    BackupScheduleOptions::$cron = '30 4 * * 2';
    $this->refreshApplication();
    $event = registeredBackupPruneSchedule();

    expect($event)->not->toBeNull()
        ->and(Event::normalizeCommand((string) $event?->command))->toBe('php artisan capell:backup:prune --force')
        ->and($event?->getExpression())->toBe('30 4 * * 2')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});

it('does not schedule backup pruning with an empty or non-string cron', function (mixed $cron): void {
    BackupScheduleOptions::$enabled = true;
    BackupScheduleOptions::$cron = $cron;
    $this->refreshApplication();
    expect(registeredBackupPruneSchedule())->toBeNull();
})->with([
    'empty' => '',
    'integer' => 1,
]);
