<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Data\Privacy\PrivacyReviewCandidateData;
use Capell\Core\Data\Privacy\PrivacySubject;

/**
 * Optional for a PrivacySubjectEraser whose data is also held by another system, such as a mailing provider.
 * Called only for an erasure, once the subject is otherwise ready, it asks the other system to erase its copy and
 * returns the erasures still outstanding. Each holds the request for review, so a request is never reported done
 * while another system still holds the person's data.
 */
interface PreparesPrivacyErasure
{
    /** @return list<PrivacyReviewCandidateData> outstanding external erasures, each naming this eraser's contributor key */
    public function prepareErasure(PrivacySubject $subject): array;
}
