<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Assets;

use Capell\Frontend\Enums\CrossOrigin;
use Capell\Frontend\Enums\FrontendResourceKind;
use Capell\Frontend\Enums\FrontendResourcePlacement;
use Capell\Frontend\Enums\FrontendResourceSourceKind;
use Capell\Frontend\Enums\ReferrerPolicy;
use Capell\Frontend\Enums\ScriptExecutionMode;
use Spatie\LaravelData\Data;

final class ResolvedFrontendResourceData extends Data
{
    /** @param array<int, string> $dependsOn */
    public function __construct(
        public readonly string $token,
        public readonly string $handle,
        public readonly string $package,
        public readonly FrontendResourceKind $kind,
        public readonly ?string $url,
        public readonly ?string $content,
        public readonly FrontendResourcePlacement $placement,
        public readonly array $dependsOn,
        public readonly bool $criticalCssEligible,
        public readonly ?ScriptExecutionMode $executionMode,
        public readonly bool $defer,
        public readonly bool $async,
        public readonly ?string $integrity = null,
        public readonly ?CrossOrigin $crossOrigin = null,
        public readonly ?ReferrerPolicy $referrerPolicy = null,
        public readonly ?FrontendResourceSourceKind $sourceKind = null,
        public readonly ?string $localPath = null,
    ) {}
}
