<?php

declare(strict_types=1);

namespace Capell\Core\Events\Capabilities;

use Capell\Core\Data\Capabilities\SiteAccessRegistrationData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A gated-site registration was approved.
 */
final readonly class SiteAccessRegistrationApproved
{
    use Dispatchable;

    public function __construct(
        public SiteAccessRegistrationData $registration,
    ) {}
}
