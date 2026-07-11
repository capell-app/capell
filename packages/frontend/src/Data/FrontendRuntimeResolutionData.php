<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Enums\FrontendRuntime;
use Spatie\LaravelData\Data;

class FrontendRuntimeResolutionData extends Data
{
    public function __construct(
        public FrontendRuntime $runtime,
        public FrontendRuntimeManifestData $runtimeManifest,
    ) {}
}
