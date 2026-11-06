<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\PublicRead;

use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;

/**
 * Default batch evaluation for a contributor with no cheaper batch path.
 *
 * @experimental
 *
 * @phpstan-require-implements PublicPageReadEligibility
 */
trait EvaluatesPublicPageReadCandidatesIndividually
{
    /**
     * @param  list<PublicPageReadCandidateData>  $candidates
     * @return list<bool>
     */
    public function arePubliclyReadable(array $candidates): array
    {
        return array_map(fn (PublicPageReadCandidateData $candidate): bool => $this->isPubliclyReadable($candidate), $candidates);
    }
}
