<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Enums\ConsentDecision;

/**
 * Implemented by a ConsentDecisionLookup whose stored decisions have stable, increasing IDs. Queued work that acts on a
 * grant records the ID of the latest stored decision when it is queued, and only while that decision is a grant. When it
 * runs it asks again, and acts only when the latest stored decision is still that same grant: any newer decision,
 * granted or refused, or a stamped decision that no longer exists, means it must not act.
 *
 * ConsentDecisionsChanged carries the first ID its save wrote, so a listener that clears queued work clears only work
 * stamped before that save.
 *
 * A lookup that does not implement it gives queued work nothing to match, so integrations that need it must not act.
 */
interface RevisesConsentDecisions
{
    /**
     * The subject's latest stored decision for a ConsentCategory value on this site, judged as decisionFor() judges it,
     * with that decision's ID; null when none is stored.
     *
     * @return array{id: int, decision: ConsentDecision}|null
     */
    public function latestDecision(string $subject, ?int $siteId, string $category): ?array;
}
