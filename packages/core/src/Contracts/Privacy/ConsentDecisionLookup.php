<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Enums\ConsentDecision;

/**
 * The server-side authority on a consent subject's current decision, read without a request in hand. A queued
 * integration that stored a subject (see IdentifiesConsentSubject) asks it immediately before it acts, so a visitor's
 * withdrawal, or a consent policy that has since changed, stops the work even when no event reached it.
 *
 * Core binds DefaultConsentDecisionLookup, which knows no subjects and answers Undecided. A consent manager that records
 * decisions binds its own. Undecided must be treated as refused for analytics and marketing signals.
 */
interface ConsentDecisionLookup
{
    /**
     * The subject's current decision for a ConsentCategory value on this site, under that site's current consent policy;
     * Undecided when nothing current is recorded for it. A consent cookie can be shared by several sites on one host, so
     * a decision recorded on one site never answers for another. A null site means decisions recorded with no site.
     */
    public function decisionFor(string $subject, string $category, ?int $siteId): ConsentDecision;
}
