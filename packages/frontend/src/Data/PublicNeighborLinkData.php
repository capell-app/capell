<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

final class PublicNeighborLinkData extends Data implements PublicRenderData
{
    public function __construct(
        public readonly ?string $url,
        public readonly ?string $direction,
        public readonly string $title,
        public readonly string $label,
        public readonly ?string $summary,
        public readonly ?string $renderedImage,
    ) {}
}
