<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class SiteAccessPolicyData extends Data
{
    /**
     * @param  list<string>  $methods
     * @param  list<string>  $sources
     */
    public function __construct(
        public readonly bool $active,
        public readonly array $methods = [],
        public readonly int $revision = 1,
        public readonly array $sources = [],
        public readonly bool $configurationAvailable = true,
    ) {}
}
