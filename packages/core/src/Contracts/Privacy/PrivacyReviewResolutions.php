<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Data\Privacy\PrivacySubject;

/**
 * How reviewers resolved an eraser's review candidates for a subject. The package that runs privacy requests binds
 * it; without one nothing has been resolved, so an eraser treats every candidate as unresolved.
 */
interface PrivacyReviewResolutions
{
    /** @return array<int, string> resolution value keyed by candidate id */
    public function for(PrivacySubject $subject, string $contributor, string $candidateType): array;
}
