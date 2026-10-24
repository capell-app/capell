<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Persistent, independently keyed grants; never a session or cache snapshot. */
final class AdminUploadGrantRepository
{
    /** @var array<int, array<string, Closure(): void>> */
    private array $pending = [];

    public function __construct(Dispatcher $events)
    {
        // These events run after PDO rollback AND transaction-manager cleanup.
        // Reapply on each savepoint rollback, retaining the journal until the
        // outer transaction ends. This also works on SQLite :memory: without
        // a second connection, and with Laravel's testing transaction manager.
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $id = spl_object_id($event->connection);
            foreach ($this->pending[$id] ?? [] as $write) {
                $write();
            }

            if ($event->connection->transactionLevel() === 0) {
                unset($this->pending[$id]);
            }
        });
        $events->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            if ($event->connection->transactionLevel() === 0) {
                unset($this->pending[spl_object_id($event->connection)]);
            }
        });
    }

    public function grant(string $scope, string $path): void
    {
        $connection = DB::connection();
        $row = [
            'scope_hash' => $scope,
            'path_hash' => hash('sha256', $path),
            'path' => $path,
            'expires_at' => now()->addMinutes((int) config('session.lifetime', 120))->toDateTimeString(),
        ];
        // Duplicate issuance must not refresh the original expiry or journal.
        if ($this->query()->insertOrIgnore($row) && $connection->transactionLevel() > 0) {
            $this->pending[spl_object_id($connection)][$scope . $row['path_hash']] = static function () use ($connection, $row): void {
                $connection->table('admin_upload_grants')->insertOrIgnore($row);
            };
        }
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    public function paths(string $scope, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        /** @var list<string> $paths */
        $paths = $this->query()
            ->where('scope_hash', $scope)
            ->whereIn('path_hash', array_map(static fn (string $path): string => hash('sha256', $path), $candidates))
            ->where('expires_at', '>', now())
            ->pluck('path')->all();

        return array_values(array_intersect($candidates, $paths));
    }

    public function forget(string $scope, string $path): void
    {
        $connection = DB::connection();
        $write = static function () use ($connection, $scope, $path): void {
            $connection->table('admin_upload_grants')->where('scope_hash', $scope)->where('path_hash', hash('sha256', $path))->delete();
        };
        $write();
        if ($connection->transactionLevel() > 0) {
            // A physical file deletion must survive content rollback as well.
            $this->pending[spl_object_id($connection)][$scope . hash('sha256', $path)] = $write;
        }
    }

    public function prune(int $limit): int
    {
        if ($limit < 1) {
            return 0;
        }

        $expired = $this->query()->select('id')->where('expires_at', '<=', now())
            ->oldest('expires_at')->orderBy('id')->limit($limit);

        // The derived table permits a bounded self-table DELETE on MySQL and
        // MariaDB as well as PostgreSQL/SQLite, without DELETE ... LIMIT support.
        return $this->query()->whereIn('id', function (Builder $query) use ($expired): void {
            $query->fromSub($expired, 'expired_upload_grants')->select('id');
        })->delete();
    }

    private function query(): Builder
    {
        // Retry authority must not depend on replication lag. Laravel applies
        // the configured connection's table prefix to every statement.
        return DB::table('admin_upload_grants')->useWritePdo();
    }
}
