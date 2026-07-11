<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

class FrontendAssetData extends Data
{
    public function __construct(
        public string $component,
    ) {}
}
