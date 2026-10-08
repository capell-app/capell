<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class InstallRecommendationData extends Data
{
    /**
     * `packages` are always installed with the suite. `recommended` entries are pre-ticked and
     * `optional` entries are offered unticked; both map a package name to a one-line reason.
     *
     * @param  list<string>  $packages
     * @param  array<string, string>  $recommended
     * @param  array<string, string>  $optional
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $packages,
        public readonly ?string $theme = null,
        public readonly ?bool $demo = null,
        public readonly int $order = 0,
        public readonly array $recommended = [],
        public readonly array $optional = [],
    ) {}
}
