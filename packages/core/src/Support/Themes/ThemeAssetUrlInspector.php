<?php

declare(strict_types=1);

namespace Capell\Core\Support\Themes;

final class ThemeAssetUrlInspector
{
    public static function containsRootRelativeAssetUrl(string $blade): bool
    {
        return preg_match(
            '/(?:\bsrc\s*=\s*["\']\/(?!\/)|<link\b[^>]*\bhref\s*=\s*["\']\/(?!\/))/i',
            $blade,
        ) === 1;
    }
}
