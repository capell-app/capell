<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Exceptions;

use RuntimeException;

class ThemeNotFoundException extends RuntimeException
{
    public static function forKey(string $themeKey): self
    {
        return new self(sprintf('Theme [%s] is not registered.', $themeKey));
    }
}
