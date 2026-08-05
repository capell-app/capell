<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/** @return list<string> */
function scheduledMarketplaceCommands(): array
{
    return array_values(array_filter(array_map(
        static fn (Event $event): string => (string) $event->command,
        resolve(Schedule::class)->events(),
    ), static fn (string $command): bool => str_contains($command, 'capell:marketplace:')));
}

function containsScheduledCommand(string $needle): bool
{
    return array_any(scheduledMarketplaceCommands(), fn (string $command): bool => str_contains($command, $needle));
}

it('schedules the marketplace heartbeat so update detection does not depend on someone logging in', function (): void {
    expect(containsScheduledCommand('capell:marketplace:heartbeat'))->toBeTrue();
});

it('does not schedule automatic updates unless the site has opted in', function (): void {
    expect(containsScheduledCommand('capell:marketplace:auto-update'))->toBeFalse();
});

it('registers the heartbeat and auto-update commands with artisan', function (): void {
    expect(array_keys(Artisan::all()))
        ->toContain('capell:marketplace:heartbeat')
        ->toContain('capell:marketplace:auto-update');
});
