<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Frontend\Enums\RenderingStrategyEnum;
use Spatie\LaravelData\Data;

class FrontendRuntimeManifestData extends Data
{
    /**
     * @param  array<string, bool>  $modules
     */
    public function __construct(
        public RenderingStrategyEnum $renderingStrategy,
        public bool $usesLivewire,
        public bool $usesAlpine,
        public bool $usesBeacon,
        public bool $usesWireNavigate,
        public bool $usesIslands,
        public array $modules = [],
        public bool $usesInertia = false,
    ) {}

    public static function forRenderingStrategy(RenderingStrategyEnum $strategy): self
    {
        return match ($strategy) {
            RenderingStrategyEnum::BladeOnly => new self(
                renderingStrategy: $strategy,
                usesLivewire: false,
                usesAlpine: false,
                usesBeacon: false,
                usesWireNavigate: false,
                usesIslands: false,
            ),
            RenderingStrategyEnum::BladeWithIslands => new self(
                renderingStrategy: $strategy,
                usesLivewire: true,
                usesAlpine: false,
                usesBeacon: false,
                usesWireNavigate: false,
                usesIslands: true,
            ),
            RenderingStrategyEnum::FullLivewire => new self(
                renderingStrategy: $strategy,
                usesLivewire: true,
                usesAlpine: true,
                usesBeacon: false,
                usesWireNavigate: true,
                usesIslands: false,
            ),
        };
    }
}
