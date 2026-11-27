<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

/**
 * One hydrated link in an application area's navigation.
 *
 * @experimental
 */
final class ApplicationNavigationItemData extends Data
{
    /**
     * @param  list<ApplicationNavigationItemData>  $children
     */
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly bool $active = false,
        public readonly array $children = [],
    ) {}
}
