<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

final class SocialMetaData extends Data
{
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $canonicalUrl,
        public readonly ?string $imageUrl,
        public readonly string $type,
        public readonly string $twitterCard,
    ) {}
}
