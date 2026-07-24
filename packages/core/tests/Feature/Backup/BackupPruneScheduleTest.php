<?php

declare(strict_types=1);

use Capell\Core\Providers\CapellServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function registerBackupPruneSchedule(bool $enabled, mixed $cron = '0 3 * * 1'): ?Event
{
    config([
        'backup.prune_schedule_enabled' => $enabled,
        'backup.prune_schedule_cron' => $cron,
    ]);

    $schedule = new Schedule(app());
    app()->instance(Schedule::class, $schedule);

    $provider = app()->getProvider(CapellServiceProvider::class);

    expect($provider)->toBeInstanceOf(CapellServiceProvider::class);

    $registerSchedule = new ReflectionMethod(CapellServiceProvider::class, 'registerBackupPruneSchedule');
    $registerSchedule->invoke($provider);

    return collect($schedule->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'capell:backup:prune'));
}

it('keeps destructive backup pruning unscheduled by default', function (): void {
    expect(registerBackupPruneSchedule(false))->toBeNull();
});

it('schedules forced backup pruning with overlap and server guards when enabled', function (): void {
    $event = registerBackupPruneSchedule(true, '30 4 * * 2');

    expect($event)->not->toBeNull()
        ->and(Event::normalizeCommand((string) $event?->command))->toBe('php artisan capell:backup:prune --force')
        ->and($event?->getExpression())->toBe('30 4 * * 2')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});

it('does not schedule backup pruning with an empty or non-string cron', function (mixed $cron): void {
    expect(registerBackupPruneSchedule(true, $cron))->toBeNull();
})->with([
    'empty' => '',
    'integer' => 1,
]);
