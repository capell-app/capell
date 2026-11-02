<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('registers the daily approved security scan', function (): void {
    $events = collect(resolve(Schedule::class)->events())->filter(fn (Event $event): bool => str_contains((string) $event->command, 'capell:security:scan'));
    $event = $events->sole();
    expect($event->command)->toContain('--fix-approved')
        ->and($event->expression)->toBe('10 4 * * *')
        ->and(array_keys(Artisan::all()))->toContain('capell:security:scan');
});
