<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Preview;

class ThemePreviewContext
{
    public function __construct(
        public readonly ?string $themeKey = null,
        public readonly ?string $presetKey = null,
        public readonly bool $previewing = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
