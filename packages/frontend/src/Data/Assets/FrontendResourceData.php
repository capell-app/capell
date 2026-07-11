<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Spatie\LaravelData\Data;

class FrontendResourceData extends Data
{
    public function __construct(
        public string $handle,
        public string $kind,
        public string $source,
        public ?string $buildPath,
        public PresentationLoadingStrategy $loadingStrategy = PresentationLoadingStrategy::Eager,
        public bool $defer = false,
        public bool $async = false,
        public bool $module = true,
    ) {}
}
