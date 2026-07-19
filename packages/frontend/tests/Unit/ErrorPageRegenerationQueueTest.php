<?php

declare(strict_types=1);

use Capell\Core\Octane\Resettable;
use Capell\Frontend\Support\Error\ErrorPageRegenerationQueue;

it('marks a site id as queued only on first sight', function (): void {
    $queue = new ErrorPageRegenerationQueue;

    expect($queue->markQueued(7))->toBeTrue();
    expect($queue->markQueued(7))->toBeFalse();
    expect($queue->markQueued(8))->toBeTrue();

    expect($queue->queuedSiteIds())->toContain(7)->toContain(8);
});

it('deduplicates within one request and starts clean for the next request', function (): void {
    $first = resolve(ErrorPageRegenerationQueue::class);

    expect($first->markQueued(7))->toBeTrue()
        ->and($first->markQueued(7))->toBeFalse()
        ->and(resolve(ErrorPageRegenerationQueue::class))->toBe($first);

    app()->forgetScopedInstances();

    $second = resolve(ErrorPageRegenerationQueue::class);

    expect($second)->not->toBe($first)
        ->and($second->markQueued(7))->toBeTrue();
});

it('does not participate in singleton reset infrastructure', function (): void {
    $queue = resolve(ErrorPageRegenerationQueue::class);

    expect($queue)->not->toBeInstanceOf(Resettable::class)
        ->and(collect(app()->tagged(Resettable::TAG))->contains($queue))->toBeFalse()
        ->and((new ReflectionClass($queue))->getConstructor())->toBeNull();
});
