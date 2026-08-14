<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Media;

use Spatie\LaravelData\Data;

final class MediaHealthRepairResultData extends Data
{
    /**
     * @param  list<array{id: int, reason: string}>  $skipped
     */
    public function __construct(
        public readonly int $repaired,
        public readonly array $skipped = [],
    ) {}

    public function skippedCount(): int
    {
        return count($this->skipped);
    }
}
