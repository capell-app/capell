<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Illuminate\Http\Request;

/**
 * Implemented by a consent manager that remembers who made a consent choice. The subject is an opaque, stable
 * identifier for the visitor's consent record: a server-side integration stores it when it acts on a decision, and
 * matches it against ConsentDecisionsChanged when that visitor changes their mind later, without a request in hand.
 *
 * It is never a client ID from a third party, and it must never be rendered into public output.
 */
interface IdentifiesConsentSubject
{
    /** The visitor's consent subject, or null when they have no recorded choice. */
    public function consentSubject(Request $request): ?string;
}
