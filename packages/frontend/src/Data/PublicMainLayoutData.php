<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

final class PublicMainLayoutData extends Data implements PublicRenderData
{
    public function __construct(
        public readonly string $mainClass,
        public readonly string $contentHtml,
        public readonly string $slotHtml,
        public readonly string $afterContentHtml,
    ) {}
}
