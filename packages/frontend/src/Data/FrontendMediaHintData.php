<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

class FrontendMediaHintData extends Data
{
    public function __construct(
        public readonly string $url,
        public readonly string $as = 'image',
        public readonly ?string $mimeType = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly string $fetchPriority = 'high',
    ) {}
}
