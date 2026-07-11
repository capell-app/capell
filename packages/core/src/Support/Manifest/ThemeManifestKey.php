<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest;

use Illuminate\Support\Str;

final class ThemeManifestKey
{
    public static function resolve(CapellManifestData $manifest): string
    {
        if ($manifest->themeKey !== null && $manifest->themeKey !== '') {
            return $manifest->themeKey;
        }

        return self::fromPackageName($manifest->name);
    }

    public static function fromPackageName(string $packageName): string
    {
        // Str::afterLast returns the whole string when there is no slash, so a
        // bare segment (e.g. "mytheme-theme") resolves correctly — unlike the
        // old substr(strrpos(...) + 1) which chopped the first character.
        return (string) preg_replace('/-theme$/', '', Str::afterLast($packageName, '/'));
    }
}
