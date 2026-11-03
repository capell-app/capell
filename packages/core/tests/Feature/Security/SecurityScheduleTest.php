<?php

declare(strict_types=1);

use Capell\Core\Providers\CapellServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('registers the daily approved security scan', function (): void {
    $events = collect(resolve(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains((string) $event->command, 'capell:security:scan'));
    $event = $events->sole();
    expect($event->command)->toContain('--fix-approved')->toContain('--actor=scheduler')
        ->and($event->expression)->toBe('10 4 * * *')
        ->and(array_keys(Artisan::all()))->toContain('capell:security:scan');
});

it('honours the scheduling configuration', function (bool $scheduled, string $at, bool $fix, ?string $expression, bool $hasFix): void {
    $schedule = resolve(Schedule::class);
    $before = count($schedule->events());
    config(['capell.security.scan.scheduled' => $scheduled, 'capell.security.scan.at' => $at, 'capell.security.scan.fix_approved' => $fix]);
    $provider = new CapellServiceProvider(app());
    $method = new ReflectionMethod($provider, 'registerSecurityScanSchedule');
    $method->invoke($provider);

    $added = array_slice($schedule->events(), $before);
    if (! $scheduled) {
        expect($added)->toBeEmpty();

        return;
    }

    expect($added)->toHaveCount(1)->and($added[0]->expression)->toBe($expression)
        ->and(str_contains((string) $added[0]->command, '--fix-approved'))->toBe($hasFix);
})->with([
    [false, '04:10', true, null, false],
    [true, '13:45', true, '45 13 * * *', true],
    [true, '04:10', false, '10 4 * * *', false],
]);
