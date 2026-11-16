<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

use Illuminate\Database\Eloquent\Model;

/**
 * A review hold nobody has settled yet, as PrivacyReviewLedger::open() returns it. The review id is the ledger's own
 * key, so it is only passed back to that ledger.
 */
final readonly class OpenPrivacyReviewData
{
    /**
     * @param  array{type: string, id: string}|null  $subject  the record whose request holds it, or null for an email-only request
     */
    public function __construct(
        public int|string $reviewId,
        public string $contributor,
        public string $candidateType,
        public int $candidateId,
        public ?array $subject,
    ) {}

    /** Whether the request that holds it is about this record. */
    public function isFor(Model $record): bool
    {
        return $this->subject !== null
            && in_array($this->subject['type'], [$record->getMorphClass(), $record::class], true)
            && $this->subject['id'] === (string) $record->getKey();
    }
}
