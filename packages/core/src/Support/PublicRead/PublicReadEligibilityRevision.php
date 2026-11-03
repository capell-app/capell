<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Support\TableName;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
 * later advance succeeds. Each read while unknown retries the advance once.
 *
 * @experimental
 */
final class PublicReadEligibilityRevision
{
    private const string TABLE = 'public_read_eligibility_state';

    private const string UNKNOWN_KEY = 'capell:public-read-eligibility:revision-unknown';

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

        $this->clearUnknown();

        return $this->fresh();
    }

    public function isUnknown(): bool
    {
        if ($this->unknownInProcess) {
            return true;
        }

        try {
            return Cache::get(self::UNKNOWN_KEY) === true;
        } catch (Throwable) {
            // Without the shared marker only this process knows; it already does above.
            return false;
        }
    }

    /**
     * Retries the failed advance once for this request. Until it succeeds the
     * revision stays unknown.
     */
    private function retry(): ?string
    {
        if ($this->retried) {
            return null;
        }

        $this->retried = true;

        return $this->advance();
    }

    private function markUnknown(): void
    {
        $this->unknownInProcess = true;

        try {
            Cache::forever(self::UNKNOWN_KEY, true);
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }

    private function clearUnknown(): void
    {
        $this->unknownInProcess = false;

        try {
            Cache::forget(self::UNKNOWN_KEY);
        } catch (Throwable $throwable) {
            report($throwable);
        }
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
