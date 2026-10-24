<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Models\Site;

/** Requests synchronous cleanup after publication changes have committed. */
final readonly class SiteSpecPrivacyRepairing
{
    public function __construct(public Site $site) {}
}
