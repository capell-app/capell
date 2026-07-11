<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Model;

final class SiteReplicated
{
    /**
     * @param  array<string, mixed>  $formData
     * @param  array<int|string, Pageable<Model>|Page>  $replacementPages  source page id => replica
     */
    public function __construct(
        public Site $source,
        public Site $replica,
        public array $formData,
        public array $replacementPages = [],
    ) {}
}
