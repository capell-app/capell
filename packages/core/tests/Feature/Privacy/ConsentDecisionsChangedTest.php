<?php

declare(strict_types=1);

use Capell\Core\Enums\ConsentDecision;
use Capell\Core\Events\ConsentDecisionsChanged;
use Illuminate\Support\Facades\Event;

it('tells a listener the saved decisions and treats a category the manager did not report as undecided', function (): void {
    // Bug caught: a listener reading an unreported category as granted would keep sending after a partial save.
    $heard = null;
    Event::listen(ConsentDecisionsChanged::class, static function (ConsentDecisionsChanged $event) use (&$heard): void {
        $heard = $event;
    });

    event(new ConsentDecisionsChanged('subject-1', 7, ['analytics' => ConsentDecision::Denied, 'functional' => ConsentDecision::Granted]));

    expect($heard)->toBeInstanceOf(ConsentDecisionsChanged::class)
        ->and($heard?->subject)->toBe('subject-1')->and($heard?->siteId)->toBe(7)
        ->and($heard?->decisionFor('analytics'))->toBe(ConsentDecision::Denied)
        ->and($heard?->decisionFor('functional'))->toBe(ConsentDecision::Granted)
        ->and($heard?->decisionFor('marketing'))->toBe(ConsentDecision::Undecided);
});
