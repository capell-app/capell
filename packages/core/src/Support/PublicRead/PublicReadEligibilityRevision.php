<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Support\TableName;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * An opaque token that changes whenever public-read eligibility may have
 * changed. Stored projections (render data, sitemaps, llms.txt) record it at
 * generation and are discarded when it moves.
 *
 * The token is a single database row, so every process and server reads the
 * same value and it survives cache clears. It is read once per request or
 * job (the binding is scoped). A token that cannot be read is null, and
 * callers treat that as "nothing stored may be served".
 *
 * When advancing fails, the rules have changed but the stored token still
 * names the old ones. The revision is then unknown: a marker in the shared
 * cache makes every process read null (deny, store nothing, no-store) until a
 * later advance succeeds. While unknown, one request at a time (at most once
 * every RETRY_INTERVAL_SECONDS across all processes) retries the advance.
 *
 * The marker requires a cache store shared by every process and server (the
 * same requirement as cache locks). Each failure writes a new generation token
 * under a cache lock; a successful advance clears the marker only when it
 * still holds the generation that advance observed before its own write, so a
 * newer failure is never cleared by an older success. Whenever the marker
 * cannot be written, read or compared, this process denies on its own and the
 * failure is reported: the revision never fails open.
 *
 * @experimental
 */
final class PublicReadEligibilityRevision
{
    public const int RETRY_INTERVAL_SECONDS = 10;

    private const string TABLE = 'public_read_eligibility_state';

    private const string UNKNOWN_KEY = 'capell:public-read-eligibility:revision-unknown';

    private const string LOCK_KEY = 'capell:public-read-eligibility:revision-unknown-lock';

    private const string RETRY_KEY = 'capell:public-read-eligibility:revision-retry';

    private const int LOCK_SECONDS = 10;

    private const int LOCK_WAIT_SECONDS = 3;

    private bool $loaded = false;

    private ?string $revision = null;

    private bool $unknownInProcess = false;

    private bool $retried = false;

    public function current(): ?string
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $this->revision = $this->isUnknown() ? $this->retry() : $this->read();
        }

        return $this->revision;
    }

    /**
     * Reads the stored token again, ignoring this request's copy. Use it to
     * fence a long-running generation against a change made meanwhile.
     */
    public function fresh(): ?string
    {
        $this->loaded = false;

        return $this->current();
    }

    /**
     * Replaces the token in one statement. Returns null when it could not be
     * stored, after which every read is denied until the row is readable.
     */
    public function advance(): ?string
    {
        $revision = (string) Str::ulid();
        // The failure generation this advance may clear, read before its write.
        $observed = $this->marker();

        try {
            $updated = DB::table(TableName::resolve(self::TABLE))
                ->where('id', 1)
                ->update(['revision' => $revision, 'updated_at' => now()]);

            if ($updated === 0) {
                DB::table(TableName::resolve(self::TABLE))->insertOrIgnore([
                    'id' => 1,
                    'revision' => $revision,
                    'updated_at' => now(),
                ]);
            }
        } catch (Throwable $throwable) {
            report($throwable);
            $this->markUnknown();
            $this->revision = null;
            $this->loaded = true;

            return null;
        }

        if (! $this->clearUnknown($observed)) {
            // A newer failure (or an unreadable marker) still stands.
            $this->unknownInProcess = true;
            $this->revision = null;
            $this->loaded = true;

            return null;
        }

        return $this->fresh();
    }

    public function isUnknown(): bool
    {
        if ($this->unknownInProcess) {
            return true;
        }

        try {
            return Cache::get(self::UNKNOWN_KEY) !== null;
        } catch (Throwable $throwable) {
            // Whether another process marked the rules unknown cannot be read: deny.
            report($throwable);

            return true;
        }
    }

    /**
     * Retries the failed advance, at most once per RETRY_INTERVAL_SECONDS
     * across every process (the first request to take the shared slot runs
     * it) and once per request. Every other read stays denied meanwhile.
     */
    private function retry(): ?string
    {
        if ($this->retried) {
            return null;
        }

        $this->retried = true;

        try {
            if (! Cache::add(self::RETRY_KEY, true, self::RETRY_INTERVAL_SECONDS)) {
                return null;
            }
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }

        return $this->advance();
    }

    /**
     * The current failure generation, false when none is set, or null when
     * the marker cannot be read (then nothing may be cleared).
     */
    private function marker(): string|false|null
    {
        try {
            $marker = Cache::get(self::UNKNOWN_KEY);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }

        if ($marker === null) {
            return false;
        }

        return is_string($marker) ? $marker : null;
    }

    private function markUnknown(): void
    {
        $this->unknownInProcess = true;
        $generation = (string) Str::ulid();

        try {
            $this->warnUnlessShared();
            $written = $this->locked(static function () use ($generation): bool {
                Cache::forever(self::UNKNOWN_KEY, $generation);

                return true;
            });

            if (! $written) {
                // Without the lock the marker is still written: a newer failure must stand.
                Cache::forever(self::UNKNOWN_KEY, $generation);
            }
        } catch (Throwable $throwable) {
            // This process still denies; other processes cannot learn of it.
            report($throwable);
        }
    }

    /**
     * Removes the marker only if it still holds $observed (compare and
     * delete under the lock). Returns whether the revision is now known.
     */
    private function clearUnknown(string|false|null $observed): bool
    {
        if ($observed === null) {
            return false;
        }

        if ($observed === false) {
            $current = $this->marker();
            $this->unknownInProcess = $current !== false;

            return $current === false;
        }

        try {
            $cleared = $this->locked(static function () use ($observed): bool {
                if (Cache::get(self::UNKNOWN_KEY) !== $observed) {
                    return false;
                }

                Cache::forget(self::UNKNOWN_KEY);

                return true;
            });
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }

        $this->unknownInProcess = ! $cleared;

        return $cleared;
    }

    /**
     * @param  callable(): bool  $callback
     */
    private function locked(callable $callback): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return false;
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function warnUnlessShared(): void
    {
        $store = config('cache.stores.' . config('cache.default') . '.driver');

        report_if(in_array($store, ['array', 'null'], true) && ! app()->runningUnitTests(), new RuntimeException('Public-read eligibility needs a cache store shared by every process; the "' . $store . '" store keeps the revision-unknown state in this process only.'));
    }

    private function read(): ?string
    {
        try {
            $revision = DB::table(TableName::resolve(self::TABLE))->where('id', 1)->value('revision');
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }

        return is_string($revision) && $revision !== '' ? $revision : null;
    }
}
