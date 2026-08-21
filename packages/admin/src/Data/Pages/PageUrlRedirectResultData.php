<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Spatie\LaravelData\Data;

final class PageUrlRedirectResultData extends Data
{
    public function __construct(
        public readonly int $acceptedCount,
        public readonly int $recordedCount,
    ) {}
}
