<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Enums\SiteLicenceState;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/** The last licence check, kept locally so the admin notice never waits on the network. */
final class SiteLicenceStatusData extends Data
{
    /** @param list<string> $unlicensedPackages Paid packages on this site that no key covers */
    public function __construct(
        public readonly SiteLicenceState $state,
        public readonly array $unlicensedPackages,
        public readonly ?int $sitesUsed,
        public readonly ?int $siteLimit,
        public readonly CarbonImmutable $checkedAt,
    ) {}
}
