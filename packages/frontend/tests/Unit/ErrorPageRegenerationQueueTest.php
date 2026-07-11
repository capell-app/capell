<?php

declare(strict_types=1);

use Capell\Frontend\Support\Error\ErrorPageRegenerationQueue;

it('marks a site id as queued only on first sight', function (): void {
    $queue = new ErrorPageRegenerationQueue;

    expect($queue->markQueued(7))->toBeTrue();
    expect($queue->markQueued(7))->toBeFalse();
    expect($queue->markQueued(8))->toBeTrue();

    expect($queue->queuedSiteIds())->toContain(7)->toContain(8);
});

it('is resolved from the container as a singleton', function (): void {
    $first = resolve(ErrorPageRegenerationQueue::class);
    $second = resolve(ErrorPageRegenerationQueue::class);

    expect($first)->toBe($second);
});
