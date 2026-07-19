<?php

declare(strict_types=1);

namespace Capell\Core\Data\RuntimeRefresh;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

final class RuntimeRefreshResultData extends Data
{
    /** @param Collection<int, RuntimeRefreshStageResultData> $stages */
    public function __construct(public readonly Collection $stages) {}

    public function passed(): bool
    {
        return $this->stages->every(
            fn (RuntimeRefreshStageResultData $stage): bool => $stage->passed,
        );
    }
}
