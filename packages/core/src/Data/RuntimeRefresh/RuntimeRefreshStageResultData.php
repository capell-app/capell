<?php

declare(strict_types=1);

namespace Capell\Core\Data\RuntimeRefresh;

use Spatie\LaravelData\Data;

final class RuntimeRefreshStageResultData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        public readonly string $message,
        public readonly bool $skipped = false,
    ) {}
}
