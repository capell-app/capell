<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Spatie\LaravelData\Data;

final class ExtensionFeedbackData extends Data
{
    public function __construct(
        public readonly string $slug,
        public readonly ?int $rating,
        public readonly ?string $comment,
        public readonly ?string $tip,
        public readonly ?string $domain = null,
    ) {}
}
