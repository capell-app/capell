<?php

declare(strict_types=1);

namespace Capell\Core\Support\Idempotency;

use Capell\Core\Enums\IdempotencyClaimState;
use Capell\Core\Enums\IdempotencyKeyStatus;
use Capell\Core\Exceptions\MismatchedIdempotentPayload;
use Capell\Core\Exceptions\StaleIdempotencyClaim;
use Capell\Core\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes an operation run once per key. The first caller to claim a key acquires it and performs the operation; a
 * concurrent caller sees it in progress; once completed, later callers get the stored result back instead of running it
 * again. A key reused with a different payload is refused. An expired key can be claimed afresh.
 *
 * Each acquired claim carries a token. When a claim outlives its lifetime and another caller takes the key over, the
 * new holder gets a new token, so the first caller can no longer complete or release it.
 */
final class IdempotencyKeyStore
{
    /** A stable key for one call to an external provider (an AI model, a payment gateway) within an execution. */
    public static function forProviderCall(string $executionId): string
    {
        throw_if(trim($executionId) === '', InvalidArgumentException::class, 'A provider call key needs an execution id.');

        return 'provider-call:' . $executionId;
    }

    public function claim(string $key, string $payloadHash, int $ttlSeconds): IdempotencyClaim
    {
        throw_if($ttlSeconds < 1, InvalidArgumentException::class, 'An idempotency key needs a positive lifetime.');

        return DB::transaction(function () use ($key, $payloadHash, $ttlSeconds): IdempotencyClaim {
            $row = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->first();

            if (! $row instanceof IdempotencyKey) {
                try {
                    $fresh = $this->freshClaim($key, $payloadHash, $ttlSeconds);
                    IdempotencyKey::query()->create($fresh);

                    return new IdempotencyClaim($key, IdempotencyClaimState::Acquired, token: $fresh['claim_token']);
                } catch (UniqueConstraintViolationException) {
                    $row = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->sole();
                }
            }

            if ($row->hasExpired()) {
                $fresh = $this->freshClaim($key, $payloadHash, $ttlSeconds);
                $row->fill($fresh)->save();

                return new IdempotencyClaim($key, IdempotencyClaimState::Acquired, token: $fresh['claim_token']);
            }

            throw_unless(hash_equals($row->payload_hash, $payloadHash), MismatchedIdempotentPayload::forKey($key));

            if ($row->status === IdempotencyKeyStatus::Completed) {
                return new IdempotencyClaim($key, IdempotencyClaimState::Completed, $row->result);
            }

            return new IdempotencyClaim($key, IdempotencyClaimState::InProgress);
        });
    }

    /**
     * Stores the operation's result so later claims replay it until the claim's lifetime ends, or for
     * $retainSeconds from now when given.
     *
     * @param  array<string, mixed>  $result
     *
     * @throws StaleIdempotencyClaim when another caller has taken the key over
     */
    public function complete(IdempotencyClaim $claim, array $result, ?int $retainSeconds = null): void
    {
        throw_if($retainSeconds !== null && $retainSeconds < 1, InvalidArgumentException::class, 'A completed idempotency key needs a positive retention.');

        $this->finish($claim, $result, $retainSeconds === null ? false : CarbonImmutable::now()->addSeconds($retainSeconds));
    }

    /**
     * Stores the operation's result so every later claim replays it, for operations whose result must never run
     * twice, such as a recorded payment. The key is never pruned.
     *
     * @param  array<string, mixed>  $result
     *
     * @throws StaleIdempotencyClaim when another caller has taken the key over
     */
    public function completePermanently(IdempotencyClaim $claim, array $result): void
    {
        $this->finish($claim, $result, null);
    }

    /**
     * Gives up an acquired claim after the operation failed, so a retry can claim the key again. A claim another
     * caller has since taken over is left alone, so releasing never discards the new holder's work.
     */
    public function release(IdempotencyClaim $claim): void
    {
        throw_unless($claim->isAcquired() && $claim->token !== null, InvalidArgumentException::class, 'Only an acquired idempotency claim can be released.');

        IdempotencyKey::query()
            ->where('key', $claim->key)
            ->where('status', IdempotencyKeyStatus::Pending->value)
            ->where('claim_token', $claim->token)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  CarbonImmutable|false|null  $expiresAt  false keeps the claim's expiry; null keeps the result permanently
     */
    private function finish(IdempotencyClaim $claim, array $result, CarbonImmutable|false|null $expiresAt): void
    {
        throw_unless($claim->isAcquired() && $claim->token !== null, InvalidArgumentException::class, 'Only an acquired idempotency claim can be completed.');

        DB::transaction(function () use ($claim, $result, $expiresAt): void {
            $row = IdempotencyKey::query()->where('key', $claim->key)->lockForUpdate()->first();

            throw_unless(
                $row instanceof IdempotencyKey
                    && $row->status === IdempotencyKeyStatus::Pending
                    && hash_equals((string) $row->claim_token, $claim->token),
                StaleIdempotencyClaim::forKey($claim->key),
            );

            $row->fill([
                'status' => IdempotencyKeyStatus::Completed,
                'result' => $result,
                'claim_token' => null,
                ...($expiresAt === false ? [] : ['expires_at' => $expiresAt]),
            ])->save();
        });
    }

    /** @return array{key: string, payload_hash: string, status: IdempotencyKeyStatus, claim_token: string, result: null, expires_at: CarbonImmutable} */
    private function freshClaim(string $key, string $payloadHash, int $ttlSeconds): array
    {
        return [
            'key' => $key,
            'payload_hash' => $payloadHash,
            'status' => IdempotencyKeyStatus::Pending,
            'claim_token' => bin2hex(random_bytes(32)),
            'result' => null,
            'expires_at' => CarbonImmutable::now()->addSeconds($ttlSeconds),
        ];
    }
}
