<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

class FrontendResourceAssetSizeData extends Data
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public string $handle,
        public string $kind,
        public string $source,
        public ?string $buildPath,
        public ?int $rawBytes,
        public ?int $gzipBytes,
        public bool $measurable,
        public array $warnings = [],
    ) {}
}
