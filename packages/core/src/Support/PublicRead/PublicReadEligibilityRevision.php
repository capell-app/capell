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
 * names the old ones. The revision is then unknown until a later advance
 * succeeds, and every process reads null (deny, store nothing, no-store).
 * While unknown, one request at a time (at most once every
 * RETRY_INTERVAL_SECONDS across all processes) retries the advance.
 *
 * The unknown state is two numbers in the shared cache:
 *
 * - a failure counter, which every failed advance raises with an atomic
 *   Cache::increment, with or without the lock, and which never goes down;
 * - a cleared mark, the highest failure generation a successful advance has
 *   covered.
 *
 * The revision is unknown while the counter is above the cleared mark. A
 * successful advance moves the mark, under the lock, only up to the counter
 * value it observed before its own write, so a failure that happened after
 * that observation (a newer one) is never cleared by it, and a generation is
 * never reused. Both keys require a cache store shared by every process and
 * server (the same requirement as cache locks) that does not evict keys
 * stored without an expiry. Whenever they cannot be written, read or
 * compared, this process denies on its own and the failure is reported: the
 * revision never fails open.
 *
 * A failed advance also writes a fresh unknown token to the database row when
 * it can, so a cache clear (which loses both numbers) does not make the old
 * revision trusted again: the revision is unknown while that token is set. A
 * successful advance clears only the token it read before its own write, so
 * a failure recorded after that read still stands.
 *
 * None of this is what correctness rests on. Each advance also records a
 * fingerprint of the stored rules, and the public-read reconciler
 * (ReconcilePublicReadProjectionsAction, every minute) advances the revision
 * whenever the rules no longer match it, then withdraws every stored copy
 * built under another revision. A lost failure counter, token or event
 * therefore delays withdrawal by at most one reconciler run.
 *
 * @experimental
 */
final class PublicReadEligibilityRevision
{
    public const int RETRY_INTERVAL_SECONDS = 10;

    private const string TABLE = 'public_read_eligibility_state';

    private const string FAILURES_KEY = 'capell:public-read-eligibility:revision-failures';

    private const string CLEARED_KEY = 'capell:public-read-eligibility:revision-failures-cleared';

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
            if ($this->cacheSaysUnknown()) {
                $this->revision = $this->retry();
            } else {
                // An unreadable row denies; a row a failed advance marked unknown is retried.
                $row = $this->readRow();
                $this->revision = $row === null ? null : ($row['unknown'] ? $this->retry() : $row['revision']);
            }
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
        // The failure generation and token this advance may clear, read before its write.
        $observed = $this->failures();
        $token = $this->unknownToken();
        // The rules this revision names, read before its write: a change after
        // this read leaves a mismatch the reconciler advances past.
        $fingerprint = $this->rulesFingerprint();

        try {
            $updated = DB::table(TableName::resolve(self::TABLE))
                ->where('id', 1)
                ->update(['revision' => $revision, 'fingerprint' => $fingerprint, 'updated_at' => now()]);

            if ($updated === 0) {
                DB::table(TableName::resolve(self::TABLE))->insertOrIgnore([
                    'id' => 1,
                    'revision' => $revision,
                    'fingerprint' => $fingerprint,
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

        if (! $this->clearUnknown($observed) || ! $this->clearUnknownToken($token)) {
            // A newer failure (or unreadable failure state) still stands.
            $this->unknownInProcess = true;
            $this->revision = null;
            $this->loaded = true;

            return null;
        }

        return $this->fresh();
    }

    /**
     * The durable state as stored: the revision, the fingerprint of the rules
     * it was advanced under, and whether a failed advance marked it unknown.
     * Null when the row cannot be read. Ignores this request's copy and the
     * shared cache.
     *
     * @return array{revision: string|null, fingerprint: string|null, unknown: bool}|null
     */
    public function durable(): ?array
    {
        return $this->readRow();
    }

    public function isUnknown(): bool
    {
        if ($this->cacheSaysUnknown()) {
            return true;
        }

        $row = $this->readRow();

        return $row === null || $row['unknown'];
    }

    private static function count(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : 0;
    }

    private function cacheSaysUnknown(): bool
    {
        if ($this->unknownInProcess) {
            return true;
        }

        try {
            $state = Cache::many([self::FAILURES_KEY, self::CLEARED_KEY]);
            $failures = $state[self::FAILURES_KEY] ?? null;

            // A value that is not a count cannot be trusted: deny.
            return ($failures !== null && ! is_numeric($failures))
                || self::count($failures) > self::count($state[self::CLEARED_KEY] ?? null);
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
     * The failure generation reached so far (0 when there has been none), or
     * null when it cannot be read (then nothing may be cleared).
     */
    private function failures(): ?int
    {
        try {
            return self::count(Cache::get(self::FAILURES_KEY));
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }

    private function markUnknown(): void
    {
        $this->unknownInProcess = true;

        try {
            $this->warnUnlessShared();
            $generation = Cache::increment(self::FAILURES_KEY);

            if ($generation === false) {
                // Stores that cannot increment a missing key: create it atomically first.
                Cache::add(self::FAILURES_KEY, 0, now()->addYears(10));
                $generation = Cache::increment(self::FAILURES_KEY);
            }

            throw_unless(is_int($generation) && $generation > 0, RuntimeException::class, 'The public-read eligibility failure counter could not be raised.');

            $cleared = self::count(Cache::get(self::CLEARED_KEY));

            if ($generation <= $cleared) {
                // The counter was lost while the mark survived: lift it above the mark.
                Cache::increment(self::FAILURES_KEY, $cleared - $generation + 1);
            }
        } catch (Throwable $throwable) {
            // This process still denies; other processes learn of it from the database token.
            report($throwable);
        }

        try {
            DB::table(TableName::resolve(self::TABLE))->where('id', 1)->update(['unknown_token' => (string) Str::ulid()]);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    /**
     * The unknown token stored before this advance's write: null when none,
     * false when it cannot be read (then it may not be cleared).
     */
    private function unknownToken(): string|false|null
    {
        try {
            $token = DB::table(TableName::resolve(self::TABLE))->where('id', 1)->value('unknown_token');
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * Clears the token read before this advance's write, and only that one.
     * Returns whether no token is left standing.
     */
    private function clearUnknownToken(string|false|null $token): bool
    {
        if ($token === false) {
            return false;
        }

        try {
            if ($token !== null) {
                DB::table(TableName::resolve(self::TABLE))->where('id', 1)->where('unknown_token', $token)->update(['unknown_token' => null]);
            }

            $known = $this->unknownToken() === null;
        } catch (Throwable $throwable) {
            report($throwable);
            $known = false;
        }

        $this->unknownInProcess = ! $known;

        return $known;
    }

    /**
     * Marks every failure up to $observed (read before the successful write)
     * as covered, under the lock and never downwards. Returns whether the
     * revision is now known: false while a later failure stands, or when the
     * state cannot be read or the lock had.
     */
    private function clearUnknown(?int $observed): bool
    {
        if ($observed === null) {
            return false;
        }

        try {
            $known = $this->locked(static function () use ($observed): bool {
                $cleared = self::count(Cache::get(self::CLEARED_KEY));

                if ($observed > $cleared) {
                    Cache::forever(self::CLEARED_KEY, $observed);
                    $cleared = $observed;
                }

                return self::count(Cache::get(self::FAILURES_KEY)) <= $cleared;
            });
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }

        $this->unknownInProcess = ! $known;

        return $known;
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

    /**
     * The stored revision and whether a failed advance left it unknown, in
     * one read, or null when the row cannot be read.
     *
     * @return array{revision: string|null, fingerprint: string|null, unknown: bool}|null
     */
    private function readRow(): ?array
    {
        try {
            $row = DB::table(TableName::resolve(self::TABLE))->where('id', 1)->first(['revision', 'fingerprint', 'unknown_token']);
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }

        $revision = $row->revision ?? null;
        $fingerprint = $row->fingerprint ?? null;
        $token = $row->unknown_token ?? null;

        return [
            'revision' => is_string($revision) && $revision !== '' ? $revision : null,
            'fingerprint' => is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : null,
            'unknown' => is_string($token) && $token !== '',
        ];
    }

    /** The fingerprint of the stored rules, or null when they cannot be read. */
    private function rulesFingerprint(): ?string
    {
        try {
            return resolve(PublicPageReadEligibilityRegistry::class)->fingerprint();
        } catch (Throwable $throwable) {
            report($throwable);

            return null;
        }
    }
}
