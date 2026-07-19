<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Data;

use Spatie\LaravelData\Data;

final class ThemeFrontendBuildAssetsData extends Data
{
    public function __construct(
        public string $cssSource,
        public string $cssBuildInput,
        public string $condition,
    ) {}
}
