<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Activity;

use Spatie\LaravelData\Data;

final class ActivityChangedFieldData extends Data
{
    public function __construct(
        public readonly string $path,
        public readonly mixed $beforeValue,
        public readonly mixed $afterValue,
        public readonly string $status,
        public readonly bool $reversible,
        public readonly ?string $skipReason = null,
        public readonly ?string $label = null,
        public readonly ?string $displayHint = null,
    ) {}
}
