<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

final class PublicAssetTileData extends Data implements PublicRenderData
{
    public function __construct(
        public readonly string $title,
        public readonly string $headingSize,
        public readonly string $summary,
        public readonly string $url,
        public readonly string $linkText,
        public readonly string $icon,
        public readonly ?string $size,
        public readonly bool $titleBalance,
        public readonly bool $roundedImages,
        public readonly bool $imageOnTop,
        public readonly bool $imageOnRight,
        public readonly ?string $renderedImage,
        public readonly bool $hasParent,
        public readonly ?string $parentUrl,
        public readonly ?string $parentLabel,
        public readonly ?string $date,
        public readonly ?string $dateTime,
        public readonly string $datePosition,
        public readonly ?string $authorName,
        public readonly string $beforeContent,
        public readonly string $beforeTitle,
        public readonly string $afterTitle,
        public readonly string $footer,
    ) {}
}
