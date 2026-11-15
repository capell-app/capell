<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use Capell\Core\Contracts\Privacy\PrivacyReviewLedger;
use Capell\Core\Data\Privacy\OpenPrivacyReviewData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Illuminate\Database\Eloquent\Model;
use Override;

/** Used when no package runs privacy reviews: there are no holds and nothing is retained. */
final class EmptyPrivacyReviewLedger implements PrivacyReviewLedger
{
    #[Override]
    public function retained(string $contributor): array
    {
        return [];
    }

    #[Override]
    public function open(string $contributor, string $candidateType, array $candidateIds, bool $includeDelivered = false, bool $onOpenRequestsOnly = false): array
    {
        return [];
    }

    #[Override]
    public function resolve(OpenPrivacyReviewData $review, string $resolution, Model $resolver, string $note, string $channel): bool
    {
        return false;
    }

    #[Override]
    public function held(PrivacySubject $subject, string $contributor, string $candidateType): array
    {
        return [];
    }
}
