<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Models\Site;
use Closure;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * The lock shared by every out-of-band public artefact write and every
 * withdrawal of those artefacts, keyed by site. A write holds its site's lock
 * from its write-time eligibility check until the bytes are on disk; a
 * withdrawal holds the lock of every site it purges. So a render that started
 * under the old rules either writes before the purge (and is purged) or
 * fails its write-time check.
 *
 * Locks are always taken in ascending site order, with the site-less scope
 * first, so a writer and a multi-site withdrawal never wait on each other in
 * a cycle. It requires a cache store shared by every process and server.
 *
 * @experimental
 */
final class PublicArtefactPublicationLock
{
    public const string KEY_PREFIX = 'capell:public-read:publication:';

    public const int LOCK_SECONDS = 60;

    /**
     * @param  array<int, int|null>  $siteIds
     * @return list<string>
     */
    public static function keys(array $siteIds): array
    {
        $scopes = array_unique(array_map(static fn (?int $siteId): int => $siteId ?? 0, $siteIds));
        sort($scopes);

        return array_map(static fn (int $scope): string => self::KEY_PREFIX . ($scope === 0 ? 'unscoped' : 'site:' . $scope), $scopes);
    }

    /**
     * Holds the locks of the given sites (null: artefacts not tied to a site)
     * while $callback runs. The callback is given a check that tells whether
     * every lock is still held (its lease has not run out). Throws
     * LockTimeoutException when a lock cannot be had within $waitSeconds,
     * after releasing any already held.
     *
     * @template T
     *
     * @param  array<int, int|null>  $siteIds
     * @param  Closure(Closure(): bool): T  $callback
     * @return T
     */
    public function hold(array $siteIds, int $waitSeconds, Closure $callback): mixed
    {
        /** @var list<Lock> $held */
        $held = [];

        try {
            foreach (self::keys($siteIds) as $key) {
                $lock = Cache::lock($key, self::LOCK_SECONDS);
                $lock->block($waitSeconds);
                $held[] = $lock;
            }

            return $callback(static fn (): bool => array_all(
                $held,
                static fn (Lock $lock): bool => ! $lock instanceof CacheLock || $lock->isOwnedByCurrentProcess(),
            ));
        } finally {
            foreach (array_reverse($held) as $lock) {
                // The lease ran out while held: a withdrawal may have run meanwhile.
                report_unless($lock->release(), new LockTimeoutException('A public artefact publication lock expired while it was held.'));
            }
        }
    }

    /**
     * Holds the lock of every site and of artefacts not tied to a site.
     *
     * @template T
     *
     * @param  Closure(Closure(): bool): T  $callback
     * @return T
     */
    public function holdAll(int $waitSeconds, Closure $callback): mixed
    {
        return $this->hold([null, ...array_map(intval(...), Site::query()->pluck('id')->all())], $waitSeconds, $callback);
    }
}
