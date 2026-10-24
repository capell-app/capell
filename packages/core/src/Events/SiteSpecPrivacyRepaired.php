<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Data\SiteSpec\SiteSpecPrivacyRepairData;

/**
 * Core's public output has been cleaned; completion awaits all listeners.
 *
 * Listeners must run synchronously, finish invalidation and edge purges before
 * returning, and throw on failure so the repair can retry without a marker.
 */
final readonly class SiteSpecPrivacyRepaired
{
    public function __construct(public SiteSpecPrivacyRepairData $data) {}
}
