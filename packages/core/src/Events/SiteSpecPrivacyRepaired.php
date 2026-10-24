<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Models\Site;

final class SiteSpecPrivacyRepaired
{
    public function __construct(public readonly Site $site) {}
}
