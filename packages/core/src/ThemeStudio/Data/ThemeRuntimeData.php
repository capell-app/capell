<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Capell\Core\ThemeStudio\Contracts\ThemeRenderer;
use Spatie\LaravelData\Data;

class ThemeRuntimeData extends Data
{
    public function __construct(
        public string $themeKey,
        public string $presetKey,
        public ThemeDefinitionData $definition,
        public ThemePresetData $preset,
        public BrandProfileData $brand,
        public ?ThemeRenderer $renderer,
        public string $assetKey,
        public bool $previewing = false,
        public ?string $tokenCssPath = null,
        /** @var array<int, string> */
        public array $tokenIssues = [],
    ) {}
}
