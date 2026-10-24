<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Media;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Persistent, independently keyed grants; never a session or cache snapshot. */
final class AdminUploadGrantRepository
{
    public function grant(string $scope, string $path): void
    {
        // Duplicate issuance must not refresh the original expiry.
        $this->query()->insertOrIgnore([
            'scope_hash' => $scope,
            'path_hash' => hash('sha256', $path),
            'path' => $path,
            'expires_at' => now()->addMinutes((int) config('session.lifetime', 120)),
        ]);
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
        $this->query()->where('scope_hash', $scope)->where('path_hash', hash('sha256', $path))->delete();
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
