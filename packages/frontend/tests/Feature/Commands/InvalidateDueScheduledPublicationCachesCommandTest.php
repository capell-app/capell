<?php

declare(strict_types=1);

use Capell\Frontend\Actions\InvalidateDueScheduledPublicationCachesAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('registers the scheduled publication invalidator every minute with cluster-safe locks', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'capell:invalidate-due-scheduled-publications'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('* * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue()
        ->and($event?->onOneServer)->toBeTrue();
});

it('reports the number of scheduled publication cache entries invalidated', function (): void {
    InvalidateDueScheduledPublicationCachesAction::shouldRun()->andReturn(2);

    expect(Artisan::call('capell:invalidate-due-scheduled-publications'))->toBe(0)
        ->and(Artisan::output())->toContain('Invalidated 2 scheduled publication cache entries.');
});
