<?php

declare(strict_types=1);

namespace Capell\Core\Jobs\PublicRead;

use Capell\Core\Actions\PublicRead\RecordPublicReadEligibilityChangeAction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs at a scheduled public-read transition (an access area opening or
 * closing) and records it as an eligibility change, so cached HTML, static
 * exports and every other stored projection are withdrawn at that moment
 * rather than at the next scheduler tick.
 *
 * Projections already stop being served at the transition on their own; this
 * withdraws copies served outside Laravel (files a web server reads from
 * disk, edge caches) and triggers regeneration.
 *
 * @experimental
 */
final class ExpirePublicReadProjectionsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $transitionAt) {}

    public function uniqueId(): string
    {
        return 'capell-public-read-transition:' . $this->transitionAt;
    }

    public function handle(): void
    {
        // A queue that runs a job early must not record the transition before it happens.
        if (CarbonImmutable::now()->getTimestamp() < $this->transitionAt) {
            $this->release($this->transitionAt - CarbonImmutable::now()->getTimestamp());

            return;
        }

        RecordPublicReadEligibilityChangeAction::run('public-read.scheduled-transition');
    }
}
