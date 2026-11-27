<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

/**
 * Implemented by a ConsentDecisionLookup whose decisions carry a revision: a number that only ever increases as
 * decisions are recorded. Queued work stamps the revision of the grant it was queued under, then, at send time, asks
 * whether a refusal has been recorded since. A later grant never hides that refusal, and a refusal heard late never
 * cancels work queued under a newer grant (ConsentDecisionsChanged carries the refusal's revision).
 *
 * A lookup that does not implement it gives queued work no revision boundary, so integrations that need one must not
 * send under it.
 */
interface RevisesConsentDecisions
{
    /** The revision of the subject's latest recorded decision for a ConsentCategory value on this site; null when none. */
    public function latestRevision(string $subject, string $category, ?int $siteId): ?int;

    /** Whether a refusal of the category on this site has been recorded for the subject after the given revision. */
    public function refusedSince(string $subject, string $category, ?int $siteId, int $revision): bool;
}
