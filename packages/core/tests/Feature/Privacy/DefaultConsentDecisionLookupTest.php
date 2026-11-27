<?php

declare(strict_types=1);

use Capell\Core\Contracts\Privacy\ConsentDecisionLookup;
use Capell\Core\Enums\ConsentDecision;

it('answers undecided for every subject when no consent manager is installed', function (): void {
    // Bug caught: a default that granted, or no binding at all, would let queued analytics send for a subject nobody records.
    $lookup = resolve(ConsentDecisionLookup::class);

    expect($lookup->decisionFor('any-subject', 'analytics', 7))->toBe(ConsentDecision::Undecided)
        ->and($lookup->decisionFor('any-subject', 'essential', null))->toBe(ConsentDecision::Undecided);
});
