<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;

final class RecordRelationshipCountData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $count,
        public readonly bool $authoritative = true,
        public readonly ?string $url = null,
    ) {}
}
