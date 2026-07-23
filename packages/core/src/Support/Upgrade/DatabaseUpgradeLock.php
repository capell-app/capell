<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mutual exclusion for upgrades, enforced by the database rather than the cache.
 *
 * An upgrade runs migrations. Two of them at once against one database races the
 * `migrations` table and can half-apply a schema change, so this has to be a real
 * guarantee. Cache::lock is only as global as the configured store: on the `file`
 * or `array` driver, or per-node Redis, every node believes it holds the lock.
 *
 * A unique index cannot be fooled by cache topology, so the lock lives there. The
 * row carries its own expiry because a hard-killed process never gets to release
 * it, and an upgrade lock stranded forever is its own kind of outage.
 *
 * The lock table is itself created by a migration, so an installation upgrading
 * from before it existed has nowhere to put the row. In that case this falls back
 * to the cache lock, which is exactly the previous behaviour — no worse than
 * before, and it upgrades itself once the migration has run.
 */
final class DatabaseUpgradeLock
{
    public const string TABLE = 'capell_upgrade_locks';

    private const string CACHE_TOKEN_PREFIX = 'cache:';

    /**
     * Take the lock, or return null when somebody else holds it.
     *
     * @return string|null the token required to release it
     */
    public function acquire(string $name, int $ttlSeconds, ?string $owner = null): ?string
    {
        $ttlSeconds = max(1, $ttlSeconds);

        if (! $this->tableExists()) {
            return $this->acquireThroughCache($name, $ttlSeconds);
        }

        $now = Carbon::now();
        $token = (string) Str::uuid();

        $this->releaseExpired($name, $now);

        try {
            DB::table(self::TABLE)->insert([
                'name' => $name,
                'token' => $token,
                'owner' => $owner,
                'acquired_at' => $now,
                'expires_at' => $now->copy()->addSeconds($ttlSeconds),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException) {
            // The unique index rejected us: another process got there first.
            return null;
        }

        return $token;
    }

    /**
     * Release a lock we hold. Releasing one we no longer hold is a no-op, so a
     * process that overran its expiry cannot free the lock its successor took.
     */
    public function release(string $name, string $token): void
    {
        if (str_starts_with($token, self::CACHE_TOKEN_PREFIX)) {
            $this->releaseThroughCache($name, substr($token, strlen(self::CACHE_TOKEN_PREFIX)));

            return;
        }

        try {
            DB::table(self::TABLE)
                ->where('name', $name)
                ->where('token', $token)
                ->delete();
        } catch (Throwable) {
            // Losing the release is survivable: the expiry still frees the lock.
        }
    }

    /**
     * Whether the lock is currently held, without acquiring it.
     *
     * Readiness reporting needs to answer "is an upgrade running?" without taking
     * the lock, which would otherwise reject the very upgrade it is checking for.
     */
    public function isHeld(string $name): bool
    {
        if (! $this->tableExists()) {
            return $this->isHeldThroughCache($name);
        }

        try {
            return DB::table(self::TABLE)
                ->where('name', $name)
                ->where('expires_at', '>', Carbon::now())
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Without the lock table there is no way to read the cache lock's state, so
     * this takes it briefly and gives it straight back. That is what the code did
     * before the table existed, and it only applies on the fallback path.
     */
    private function isHeldThroughCache(string $name): bool
    {
        try {
            $lock = Cache::lock($name, 1);

            if ($lock->get() === false) {
                return true;
            }

            $lock->release();

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    private function acquireThroughCache(string $name, int $ttlSeconds): ?string
    {
        try {
            $lock = Cache::lock($name, $ttlSeconds);

            if ($lock->get() === false) {
                return null;
            }

            return self::CACHE_TOKEN_PREFIX . $lock->owner();
        } catch (Throwable) {
            // With no lock table and no usable cache there is nothing to coordinate
            // through. Refusing every upgrade would be worse than proceeding, which
            // is what this code did before the table existed.
            return self::CACHE_TOKEN_PREFIX;
        }
    }

    private function releaseThroughCache(string $name, string $owner): void
    {
        if ($owner === '') {
            return;
        }

        try {
            Cache::restoreLock($name, $owner)->release();
        } catch (Throwable) {
            // The lock TTL still frees it.
        }
    }

    private function tableExists(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return false;
        }
    }

    private function releaseExpired(string $name, Carbon $now): void
    {
        try {
            DB::table(self::TABLE)
                ->where('name', $name)
                ->where('expires_at', '<=', $now)
                ->delete();
        } catch (Throwable) {
            // If this fails the insert below simply reports the lock as held.
        }
    }
}
