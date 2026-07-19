<?php

declare(strict_types=1);

namespace Capell\Core\Support\Themes;

final class ThemeAssetUrlInspector
{
    public static function containsRootRelativeAssetUrl(string $blade): bool
    {
        if (preg_match('/(?<![-:\\w])src\s*=\s*["\']\/(?!\/)/i', $blade) === 1) {
            return true;
        }

        if (preg_match('/<(?:video|object)\b[^>]*(?<![-:\\w])(?:poster|data)\s*=\s*["\']\/(?!\/)/i', $blade) === 1) {
            return true;
        }

        if (preg_match('/<(?:use|image)\b[^>]*(?<![-:\\w])href\s*=\s*["\']\/(?!\/)/i', $blade) === 1) {
            return true;
        }

        if (self::containsRootRelativeSrcsetUrl($blade)) {
            return true;
        }

        return self::containsRootRelativeLinkAssetUrl($blade);
    }

    private static function containsRootRelativeSrcsetUrl(string $blade): bool
    {
        preg_match_all('/(?<![-:\\w])srcset\s*=\s*(["\'])(.*?)\1/is', $blade, $matches);

        foreach ($matches[2] as $srcset) {
            foreach (explode(',', $srcset) as $candidate) {
                $url = ltrim($candidate);

                if (self::isRootRelativeUrl($url)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function containsRootRelativeLinkAssetUrl(string $blade): bool
    {
        preg_match_all('/<link\b[^>]*>/i', $blade, $matches);

        foreach ($matches[0] as $link) {
            $href = self::attributeValue($link, 'href');
            $rel = self::attributeValue($link, 'rel');

            if ($href === null) {
                continue;
            }

            if ($rel === null) {
                continue;
            }

            if (! self::isRootRelativeUrl($href)) {
                continue;
            }

            $relations = preg_split('/\s+/', strtolower(trim($rel))) ?: [];

            if (array_intersect($relations, ['stylesheet', 'icon', 'preload', 'modulepreload', 'manifest', 'mask-icon', 'apple-touch-icon']) !== []) {
                return true;
            }
        }

        return false;
    }

    private static function attributeValue(string $tag, string $attribute): ?string
    {
        if (preg_match('/(?<![-:\\w])' . preg_quote($attribute, '/') . '\s*=\s*(["\'])(.*?)\1/is', $tag, $matches) !== 1) {
            return null;
        }

        return $matches[2];
    }

    private static function isRootRelativeUrl(string $url): bool
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//');
    }
}
