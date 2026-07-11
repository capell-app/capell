<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Spatie\LaravelData\Data;

final class PageAuthoringResultData extends Data
{
    public function __construct(
        public readonly int $redirectsRecorded = 0,
    ) {}
}
