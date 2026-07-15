<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Publishing;

use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;
use Spatie\LaravelData\Data;

final class BulkPublicationPreviewData extends Data
{
    /**
     * @param  list<array{id: int|string, label: string, result: PublicationTransitionResultData}>  $records
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly array $records,
        public readonly array $counts,
    ) {}

    public function count(PublicationTransitionOutcome $outcome): int
    {
        return $this->counts[$outcome->value] ?? 0;
    }

    public function changed(): int
    {
        return $this->count(PublicationTransitionOutcome::Changed);
    }

    public function blocked(): int
    {
        return $this->count(PublicationTransitionOutcome::Unauthorized)
            + $this->count(PublicationTransitionOutcome::InvalidTransition)
            + $this->count(PublicationTransitionOutcome::Failed);
    }

    public function unchanged(): int
    {
        return $this->count(PublicationTransitionOutcome::AlreadyCorrect);
    }
}
