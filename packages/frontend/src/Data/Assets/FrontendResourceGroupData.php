<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Spatie\LaravelData\Data;

class FrontendResourceGroupData extends Data
{
    /**
     * @param  array<int, FrontendResourceData>  $resources
     */
    public function __construct(
        public string $key,
        public array $resources,
        public ?string $label = null,
        public ?string $description = null,
        public ?string $package = null,
        public string $origin = 'registry',
        public FrontendResourceValidationResultData $validation = new FrontendResourceValidationResultData,
    ) {}
}
