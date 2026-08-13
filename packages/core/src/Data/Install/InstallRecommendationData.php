<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class InstallRecommendationData extends Data
{
    /**
     * @param  list<string>  $packages
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $packages,
        public readonly ?string $theme = null,
        public readonly ?bool $demo = null,
        public readonly int $order = 0,
    ) {}
}
