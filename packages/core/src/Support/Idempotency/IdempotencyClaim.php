<?php

declare(strict_types=1);

namespace Capell\Core\Support\Idempotency;

use Capell\Core\Enums\IdempotencyClaimState;

/** The outcome of claiming an idempotency key. */
final readonly class IdempotencyClaim
{
    /**
     * @param  array<string, mixed>|null  $result
     * @param  string|null  $token  set only on an acquired claim; it fences completion to this holder
     */
    public function __construct(
        public string $key,
        public IdempotencyClaimState $state,
        public ?array $result = null,
        public ?string $token = null,
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
