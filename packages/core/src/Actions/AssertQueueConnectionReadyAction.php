<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Core\Exceptions\QueueConnectionNotReadyException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

/** @method static void run(?string $connection = null) */
final class AssertQueueConnectionReadyAction
{
    use AsFake;
    use AsObject;

    public function handle(?string $connection = null): void
    {
        $connection ??= Queue::getDefaultDriver();
        $driver = config(sprintf('queue.connections.%s.driver', $connection));

        if (! is_string($driver) || $driver === '') {
            throw new QueueConnectionNotReadyException((string) __('capell-core::queue.connection_missing', [
                'connection' => $connection,
            ]));
        }

        if ($driver === 'sync') {
            throw new QueueConnectionNotReadyException((string) __('capell-core::queue.sync_not_supported', [
                'connection' => $connection,
            ]));
        }

        if ($driver !== 'database') {
            return;
        }

        $table = config(sprintf('queue.connections.%s.table', $connection), 'jobs');
        $table = is_string($table) && $table !== '' ? $table : 'jobs';

        $databaseConnection = config(sprintf('queue.connections.%s.connection', $connection));
        $databaseConnection = is_string($databaseConnection) && $databaseConnection !== ''
            ? $databaseConnection
            : null;

        try {
            $tableExists = $databaseConnection === null
                ? Schema::hasTable($table)
                : Schema::connection($databaseConnection)->hasTable($table);
        } catch (Throwable $throwable) {
            throw new QueueConnectionNotReadyException((string) __('capell-core::queue.database_storage_unavailable', [
                'connection' => $connection,
                'table' => $table,
            ]), $throwable->getCode(), previous: $throwable);
        }

        if (! $tableExists) {
            throw new QueueConnectionNotReadyException((string) __('capell-core::queue.database_table_missing', [
                'connection' => $connection,
                'table' => $table,
            ]));
        }
    }
}
