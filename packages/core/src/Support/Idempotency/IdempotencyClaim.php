<?php

declare(strict_types=1);

namespace Capell\Core\Support\Idempotency;

use Capell\Core\Enums\IdempotencyClaimState;

/** The outcome of claiming an idempotency key. */
final readonly class IdempotencyClaim
{
    /** @param  array<string, mixed>|null  $result */
    public function __construct(
        public string $key,
        public IdempotencyClaimState $state,
        public ?array $result = null,
    ) {}

    public function isAcquired(): bool
    {
        return $this->state === IdempotencyClaimState::Acquired;
    }

    public function isCompleted(): bool
    {
        return $this->state === IdempotencyClaimState::Completed;
    }
}
