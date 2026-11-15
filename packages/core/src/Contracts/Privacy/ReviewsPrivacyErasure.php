<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Data\Privacy\PrivacyReviewCandidateData;
use Capell\Core\Data\Privacy\PrivacySubject;

/**
 * Optional for a PrivacySubjectEraser: names the records a person must review before the eraser may run, such as
 * rows shared with someone else. A privacy request is held until each candidate is resolved; the eraser reads the
 * outcomes back through PrivacyReviewResolutions. Reporting candidates must not write anything.
 */
interface ReviewsPrivacyErasure
{
    /** @return list<PrivacyReviewCandidateData> each naming this eraser's contributor key */
    public function reviewCandidates(PrivacySubject $subject): array;

    /**
     * Whether the eraser keeps rows a reviewer marked as retained and erases only the rest, so a request may
     * proceed with those rows held back instead of waiting.
     */
    public function honoursRetainedScopes(): bool;
}
