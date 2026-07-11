<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

class FrontendResourceSizeReportData extends Data
{
    /**
     * @param  array<int, FrontendResourceAssetSizeData>  $assets
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public int $rawCssBytes,
        public int $gzipCssBytes,
        public int $rawJsBytes,
        public int $gzipJsBytes,
        public array $assets,
        public array $warnings = [],
    ) {}
}
