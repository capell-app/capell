<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Override;

final class TaggedSiteSpecApplier implements SiteSpecApplier
{
    #[Override]
    public function key(): string
    {
        return 'navigation';
    }

    /** @param array<string, Page> $pagesBySlug */
    #[Override]
    public function apply(CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void {}
}
