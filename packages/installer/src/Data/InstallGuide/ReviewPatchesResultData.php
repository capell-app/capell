<?php

declare(strict_types=1);

namespace Capell\Installer\Data\InstallGuide;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

final class ReviewPatchesResultData extends Data
{
    /** @param Collection<int, ReviewedPatchData> $patches */
    public function __construct(
        public string $token,
        public Collection $patches,
    ) {}
}
