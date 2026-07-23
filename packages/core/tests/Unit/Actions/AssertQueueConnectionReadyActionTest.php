<?php

declare(strict_types=1);

use Capell\Core\Actions\AssertQueueConnectionReadyAction;
use Capell\Core\Exceptions\QueueConnectionNotReadyException;

it('accepts a configured asynchronous queue connection', function (): void {
    config([
        'queue.default' => 'background',
        'queue.connections.background' => ['driver' => 'array'],
    ]);

    expect(AssertQueueConnectionReadyAction::run())->toBeNull();
});

it('rejects a sync queue connection', function (): void {
    config([
        'queue.connections.web' => ['driver' => 'sync'],
    ]);

    expect(fn () => AssertQueueConnectionReadyAction::run('web'))
        ->toThrow(
            QueueConnectionNotReadyException::class,
            'Queue connection "web" uses the sync driver. Configure an asynchronous queue and start a worker before continuing.',
        );
});

it('rejects a database queue connection without its configured table', function (): void {
    config([
        'queue.connections.operations' => [
            'driver' => 'database',
            'connection' => null,
            'table' => 'missing_operations_jobs',
        ],
    ]);

    expect(fn () => AssertQueueConnectionReadyAction::run('operations'))
        ->toThrow(
            QueueConnectionNotReadyException::class,
            'Queue connection "operations" uses the database driver, but its "missing_operations_jobs" table is missing. Run the queue table migration before continuing.',
        );
});

it('rejects an undefined queue connection', function (): void {
    expect(fn () => AssertQueueConnectionReadyAction::run('undefined'))
        ->toThrow(
            QueueConnectionNotReadyException::class,
            'Queue connection "undefined" is not configured.',
        );
});
