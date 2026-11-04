<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\PublicRead;

use Carbon\CarbonImmutable;

/**
 * Implemented by a public-read eligibility contributor whose decisions change
 * on a schedule, with no model saved at that moment (an access area opening
 * or closing). Stored projections are valid only until the earliest upcoming
 * transition, so they never outlive the rules they were built under.
 *
 * @experimental
 */
interface SchedulesPublicReadTransitions
{
    /**
     * The earliest moment after $now at which a decision may change, or null
     * when none is scheduled. With a site, only transitions that can affect
     * that site's pages count (its own rules and those for every site); with
     * null, a transition on any site does.
     */
    public function nextPublicReadTransitionAfter(CarbonImmutable $now, ?int $siteId = null): ?CarbonImmutable;
}
