<?php

declare(strict_types=1);

namespace Capell\Core\Events;

/**
 * Public-read eligibility may have changed for some pages, so stored public
 * projections must be withdrawn or regenerated.
 *
 * @experimental
 */
final readonly class PublicReadEligibilityChanged
{
    public function __construct(
        public string $revision,
        public string $source,
        public ?int $siteId = null,
    ) {}
}
