<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Enums\ConsentDecision;
use Illuminate\Http\Request;

/**
 * Decides whether a visitor allows a consent purpose or category. Core binds
 * DefaultConsentDecisionProvider; a consent manager such as Privacy Center binds its own
 * implementation to record and honour visitors' choices.
 *
 * Server-side decisions read the request, so use them only for responses that are never
 * cached (an analytics endpoint, a server-side integration call). Public HTML is cached and
 * shared between visitors: it must defer to the browser runtime, window.CapellPrivacy.
 */
interface ConsentDecisionProvider
{
    /**
     * The category keys this provider decides on, from ConsentCategory.
     *
     * @return list<string>
     */
    public function categories(): array;

    /**
     * Named purposes mapped to the category a visitor grants or refuses for them,
     * for example ['youtube' => 'marketing'].
     *
     * @return array<string, string>
     */
    public function purposes(): array;

    /**
     * The decision for a purpose or category name. An unknown name is Undecided, and
     * Essential is always Granted. Undecided must be treated as refused for analytics and
     * marketing signals.
     */
    public function decide(string $purposeOrCategory, Request $request): ConsentDecision;
}
