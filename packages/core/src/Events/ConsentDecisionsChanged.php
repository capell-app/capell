<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Enums\ConsentDecision;

/**
 * A visitor saved new consent decisions. A consent manager dispatches it after the choice is stored, so a server-side
 * integration holding work for that subject (see IdentifiesConsentSubject) can honour a withdrawal at once, rather than
 * waiting for the visitor's next request.
 *
 * Decisions are keyed by consent category. A category the manager did not report is Undecided, which analytics and
 * marketing signals treat as refused.
 */
final readonly class ConsentDecisionsChanged
{
    /**
     * @param  array<string, ConsentDecision>  $decisions  keyed by ConsentCategory value
     */
    public function __construct(
        public string $subject,
        public ?int $siteId,
        public array $decisions,
    ) {}

    public function decisionFor(string $category): ConsentDecision
    {
        return $this->decisions[$category] ?? ConsentDecision::Undecided;
    }
}
