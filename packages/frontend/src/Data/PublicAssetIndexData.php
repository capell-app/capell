<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

final class PublicAssetIndexData extends Data implements PublicRenderData
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $headingSize,
        public readonly string $summary,
        public readonly string $url,
        public readonly string $linkText,
        public readonly string $icon,
        public readonly ?string $size,
        public readonly int|string|null $count,
        public readonly bool $titleBalance,
        public readonly bool $withSummary,
        public readonly bool $hasBullet,
        public readonly bool $hasContent,
        public readonly bool $hasImage,
        public readonly bool $hasDate,
        public readonly bool $isBlogArticleCard,
        public readonly string $cardStyle,
        public readonly string $renderedImage,
        public readonly string $renderedDate,
        public readonly string $datePosition,
        public readonly ?string $parentUrl,
        public readonly ?string $parentLabel,
        public readonly string $beforeTitle,
        public readonly string $afterTitle,
        public readonly string $footer,
    ) {}
}
