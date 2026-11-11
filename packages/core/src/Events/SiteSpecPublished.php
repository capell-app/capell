<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Models\Site;

/** Requests synchronous cache cleanup after a site's spec pages were published. */
final readonly class SiteSpecPublished
{
    public function __construct(public Site $site) {}
}
