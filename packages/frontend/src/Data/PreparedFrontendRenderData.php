<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Enums\FrontendRuntime;
use Capell\Frontend\Contracts\FrontendResponseRenderer;
use Spatie\LaravelData\Data;

final class PreparedFrontendRenderData extends Data
{
    public function __construct(
        public readonly FrontendRuntime $runtime,
        public readonly FrontendRuntimeManifestData $runtimeManifest,
        public readonly ?FrontendResponseRenderer $renderer,
        public readonly FrontendRenderContextData $renderContext,
    ) {}
}
