<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\SiteSpec;

use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;

interface SiteSpecApplier
{
    public const string TAG = 'capell.site-spec.applier';

    public function key(): string;

    /**
     * @param  array<string, Page>  $pagesBySlug
     */
    public function apply(CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void;
}
