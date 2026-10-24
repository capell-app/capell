<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

/** Immutable invalidation scope; contains no authored SiteSpec content. */
final readonly class SiteSpecPrivacyRepairData
{
    /**
     * @param  list<int>  $siteIds
     * @param  list<string>  $domains  Hostnames, without scheme, port or mount path.
     */
    public function __construct(
        public array $siteIds,
        public array $domains,
    ) {}
}
