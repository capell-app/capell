<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Models\Site;

final class SiteCreated
{
    /**
     * @param  array<string, mixed>  $formData
     */
    public function __construct(
        public Site $site,
        public array $formData,
    ) {}
}
