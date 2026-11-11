<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Unit\PHPStan\Fixtures;

use Capell\Core\Contracts\PublicRenderData;
use Capell\Core\Models\Site;

final class ModelCarryingRenderData implements PublicRenderData
{
    /** @param  array<string, Site>  $sites */
    public function __construct(
        public Site $site,
        public array $sites,
        public string $title,
    ) {}
}

final class HydratedRenderData implements PublicRenderData
{
    /** @param  array<string, string>  $labels */
    public function __construct(
        public string $title,
        public array $labels,
        public ?int $siteId = null,
    ) {}
}

final class UnmarkedModelHolder
{
    public function __construct(public Site $site) {}
}
