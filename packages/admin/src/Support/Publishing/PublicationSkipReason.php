<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Publishing;

use Capell\Core\Actions\Publishing\EvaluatePublicationTransitionAction;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Publishing\PublicationTransitionOutcome;

/**
 * Maps a typed Core publication outcome onto the short reason slug the Admin
 * bulk-action notifications translate (`*_reason_<slug>`).
 *
 * Skip reasons are derived here from what Core actually decided, never
 * re-computed from the record's dates.
 */
final class PublicationSkipReason
{
    /**
     * The slug for a non-changed outcome, or null when the record did change.
     */
    public static function for(PublicationTransitionResultData $result, string $alreadyCorrectSlug): ?string
    {
        return match ($result->outcome) {
            PublicationTransitionOutcome::Changed => null,
            PublicationTransitionOutcome::AlreadyCorrect => $alreadyCorrectSlug,
            PublicationTransitionOutcome::Unauthorized => 'unauthorized',
            PublicationTransitionOutcome::Failed => 'failed',
            PublicationTransitionOutcome::InvalidTransition => $result->reasonKey === EvaluatePublicationTransitionAction::REASON_DELETED
                ? 'trashed'
                : 'invalid',
        };
    }
}
