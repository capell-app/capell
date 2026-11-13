<?php

declare(strict_types=1);

namespace Capell\Core\Events\Capabilities;

use Capell\Core\Data\Capabilities\CampaignConversionData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A campaign recorded a conversion.
 */
final readonly class CampaignConversionRecorded
{
    use Dispatchable;

    public function __construct(
        public CampaignConversionData $conversion,
    ) {}
}
