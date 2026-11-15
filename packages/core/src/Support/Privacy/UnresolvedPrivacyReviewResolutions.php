<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use Capell\Core\Contracts\Privacy\PrivacyReviewResolutions;
use Capell\Core\Data\Privacy\PrivacySubject;
use Override;

/** Used when no package runs privacy reviews: no candidate has been resolved. */
final class UnresolvedPrivacyReviewResolutions implements PrivacyReviewResolutions
{
    #[Override]
    public function for(PrivacySubject $subject, string $contributor, string $candidateType): array
    {
        return [];
    }
}
