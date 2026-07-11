<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

final class HeadContentSanitizer
{
    public static function css(string $css): string
    {
        return (string) preg_replace('/<\/(style|script)/i', '<\/$1', $css);
    }

    public static function cssValue(?string $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $sanitized = str_replace(["\0", "\r", "\n", '<', '>', '{', '}', ';'], '', self::css($value));

        return trim($sanitized) !== '' ? $sanitized : $fallback;
    }

    public static function headSnippet(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $html = (string) preg_replace('/<(script|style|iframe|object|embed|base|title)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = strip_tags($html, '<meta><link>');
        $html = (string) preg_replace('/\s+on[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html);
        $html = (string) preg_replace('/\s+(href|src)\s*=\s*(["\'])\s*javascript:.*?\2/is', '', $html);
        $html = (string) preg_replace('/\s+(href|src)\s*=\s*javascript:[^\s>]+/is', '', $html);

        return self::css($html);
    }
}
