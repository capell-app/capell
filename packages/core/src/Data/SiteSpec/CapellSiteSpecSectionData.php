<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Data;

final class CapellSiteSpecSectionData extends Data
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $type,
        public readonly string $content,
        public readonly ?string $title = null,
        public readonly ?string $summary = null,
        public readonly int $order = 0,
        public readonly array $meta = [],
    ) {}

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return ['meta' => ['sometimes', 'array']];
    }
}
