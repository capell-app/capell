<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * @return array{0: Event, 1: string}|null matched event + its cron expression
 */
function scheduledEventFor(string $commandName): ?array
{
    foreach (resolve(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, $commandName)) {
            return [$event, $event->getExpression()];
        }
    }

    return null;
}

it('registers the soft-deleted media purge daily at 03:00', function (): void {
    $match = scheduledEventFor('capell:purge-soft-deleted-media');

    expect($match)->not->toBeNull();

    if ($match === null) {
        return;
    }

    [, $expression] = $match;
    expect($expression)->toBe('0 3 * * *');
});
