<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

/** The translated heading and rendered body of a public content block. */
final class PublicContentData extends Data implements PublicRenderData
{
    public function __construct(
        public readonly ?string $title,
        public readonly string $bodyHtml,
        public readonly bool $roundedImages,
        public readonly ?int $imageHeight,
    ) {}
}
