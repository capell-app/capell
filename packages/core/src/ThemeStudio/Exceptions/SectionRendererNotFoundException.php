<?php

declare(strict_types=1);

namespace Capell\Core\ThemeStudio\Exceptions;

use RuntimeException;

class SectionRendererNotFoundException extends RuntimeException
{
    public static function forSection(string $themeKey, string $sectionKey): self
    {
        return new self(sprintf('Theme [%s] cannot render section [%s].', $themeKey, $sectionKey));
    }
}
