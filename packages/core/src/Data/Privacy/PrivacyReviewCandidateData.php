<?php

declare(strict_types=1);

namespace Capell\Core\Data\Privacy;

use InvalidArgumentException;

/**
 * One record that a person must review before a privacy request may go ahead, such as a booking shared with
 * someone else or a copy of the data held by a mailing provider. `unscoped` names a contributor that cannot
 * scope the subject at all, with candidate id 0.
 */
final readonly class PrivacyReviewCandidateData
{
    public const string REASON_EMAIL_MATCH = 'email_match';

    public const string REASON_FOREIGN_DESCENDANT = 'foreign_descendant';

    public const string REASON_EXTERNAL_COPY = 'external_copy';

    public const string REASON_UNSCOPED = 'unscoped';

    public const array REASONS = [
        self::REASON_EMAIL_MATCH,
        self::REASON_FOREIGN_DESCENDANT,
        self::REASON_EXTERNAL_COPY,
        self::REASON_UNSCOPED,
    ];

    public function __construct(
        public string $contributor,
        public string $candidateType,
        public int $candidateId,
        public string $reason,
        public ?int $siteId = null,
    ) {
        $unscoped = $candidateType === self::REASON_UNSCOPED;

        throw_if($contributor === '' || strlen($contributor) > 64
            || $candidateType === '' || strlen($candidateType) > 255
            || ! in_array($reason, self::REASONS, true)
            || ($unscoped ? ($candidateId !== 0 || $reason !== self::REASON_UNSCOPED) : ($candidateId < 1 || $reason === self::REASON_UNSCOPED)), InvalidArgumentException::class, 'Invalid privacy review candidate scope.');
    }
}
