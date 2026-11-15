<?php

declare(strict_types=1);

namespace Capell\Core\Support\Idempotency;

use Capell\Core\Enums\IdempotencyClaimState;
use Capell\Core\Enums\IdempotencyKeyStatus;
use Capell\Core\Exceptions\MismatchedIdempotentPayload;
use Capell\Core\Models\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes an operation run once per key. The first caller to claim a key acquires it and performs the operation; a
 * concurrent caller sees it in progress; once completed, later callers get the stored result back instead of running it
 * again. A key reused with a different payload is refused. An expired key can be claimed afresh.
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
                    IdempotencyKey::query()->create($this->freshClaim($key, $payloadHash, $ttlSeconds));

                    return new IdempotencyClaim($key, IdempotencyClaimState::Acquired);
                } catch (UniqueConstraintViolationException) {
                    $row = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->sole();
                }
            }

            if ($row->hasExpired()) {
                $row->fill($this->freshClaim($key, $payloadHash, $ttlSeconds))->save();

                return new IdempotencyClaim($key, IdempotencyClaimState::Acquired);
            }

            throw_unless(hash_equals($row->payload_hash, $payloadHash), MismatchedIdempotentPayload::forKey($key));

            if ($row->status === IdempotencyKeyStatus::Completed) {
                return new IdempotencyClaim($key, IdempotencyClaimState::Completed, $row->result);
            }

            return new IdempotencyClaim($key, IdempotencyClaimState::InProgress);
        });
    }

    /**
     * Stores the operation's result so later claims replay it.
     *
     * @param  array<string, mixed>  $result
     */
    public function complete(IdempotencyClaim $claim, array $result): void
    {
        throw_unless($claim->isAcquired(), InvalidArgumentException::class, 'Only an acquired idempotency claim can be completed.');

        IdempotencyKey::query()->where('key', $claim->key)->sole()
            ->fill(['status' => IdempotencyKeyStatus::Completed, 'result' => $result])
            ->save();
    }

    /** Gives up an acquired claim after the operation failed, so a retry can claim the key again. */
    public function release(IdempotencyClaim $claim): void
    {
        throw_unless($claim->isAcquired(), InvalidArgumentException::class, 'Only an acquired idempotency claim can be released.');

        IdempotencyKey::query()
            ->where('key', $claim->key)
            ->where('status', IdempotencyKeyStatus::Pending->value)
            ->delete();
    }

    /** @return array<string, mixed> */
    private function freshClaim(string $key, string $payloadHash, int $ttlSeconds): array
    {
        return [
            'key' => $key,
            'payload_hash' => $payloadHash,
            'status' => IdempotencyKeyStatus::Pending,
            'result' => null,
            'expires_at' => CarbonImmutable::now()->addSeconds($ttlSeconds),
        ];
    }
}
