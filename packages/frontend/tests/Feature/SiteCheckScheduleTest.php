<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function frontendScheduledEventFor(string $commandName): ?Event
{
    foreach (resolve(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, $commandName)) {
            return $event;
        }
    }

    return null;
}

it('registers the frontend site check with its configured default frequency', function (): void {
    $event = frontendScheduledEventFor('capell:frontend-site-check');

    expect($event)->not->toBeNull()
        ->and($event?->getExpression())->toBe('0 0 * * *');
});
