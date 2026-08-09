<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteDomains;

use Capell\Core\Support\SiteDomains\SiteDomainAddressing;

final readonly class SiteRequestTargetData
{
    public function __construct(
        public SiteOriginData $origin,
        public string $path,
        public string $rawQuery = '',
    ) {}

    public static function fromUrl(string $url): self
    {
        return SiteDomainAddressing::targetFromUrl($url);
    }
}
