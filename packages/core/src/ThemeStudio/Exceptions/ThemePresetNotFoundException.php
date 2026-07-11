<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Exceptions;

use InvalidArgumentException;

class ThemePresetNotFoundException extends InvalidArgumentException
{
    public static function forKey(string $themeKey, string $presetKey): self
    {
        return new self(sprintf('Theme preset [%s] is not registered for theme [%s].', $presetKey, $themeKey));
    }
}
