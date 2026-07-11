<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

final class PublicHtmlSafetyDetectionData
{
    public function __construct(
        public readonly string $category,
        public readonly string $matched,
        public readonly string $reason,
    ) {}
}
