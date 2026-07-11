<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Illuminate\Support\Collection;

interface PageCreatable
{
    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, Language>  $languages
     */
    public function createPage(array $data, Site $site, Collection $languages): Pageable;
}
