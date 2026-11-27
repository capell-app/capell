<?php

declare(strict_types=1);

namespace Capell\Core\Support\Privacy;

use Capell\Core\Contracts\Privacy\ConsentDecisionLookup;
use Capell\Core\Enums\ConsentDecision;
use Override;

/**
 * The lookup Core binds when no consent manager is installed. Core records no consent subjects, so every subject is
 * Undecided, which refuses analytics and marketing work held for it.
 */
final readonly class DefaultConsentDecisionLookup implements ConsentDecisionLookup
{
    #[Override]
    public function decisionFor(string $subject, string $category, ?int $siteId): ConsentDecision
    {
        return ConsentDecision::Undecided;
    }
}
