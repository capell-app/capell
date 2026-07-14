<?php

declare(strict_types=1);

namespace Capell\Core\Data\Publishing;

use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class PublicationTransitionResultData extends Data
{
    public function __construct(
        public readonly PublicationTransitionOutcome $outcome,
        public readonly PublishVisibilityStateEnum $beforeState,
        public readonly PublishVisibilityStateEnum $afterState,
        public readonly ?CarbonImmutable $visibleFrom,
        public readonly ?CarbonImmutable $visibleUntil,
        public readonly string $reasonKey,
    ) {}

    public function changed(): bool
    {
        return $this->outcome === PublicationTransitionOutcome::Changed;
    }

    public function withOutcome(PublicationTransitionOutcome $outcome, string $reasonKey): self
    {
        return new self(
            outcome: $outcome,
            beforeState: $this->beforeState,
            afterState: $this->afterState,
            visibleFrom: $this->visibleFrom,
            visibleUntil: $this->visibleUntil,
            reasonKey: $reasonKey,
        );
    }
}
