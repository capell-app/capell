<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

final class JsonLdScriptSanitizer
{
    private const string CLOSING_SCRIPT_TAG = '</script>';

    public static function sanitize(string $jsonLd): string
    {
        return (string) preg_replace('/<\/script/i', '<\/script', $jsonLd);
    }

    public static function sanitizeScriptTag(string $script): string
    {
        if (! str_ends_with(strtolower($script), self::CLOSING_SCRIPT_TAG)) {
            return self::sanitize($script);
        }

        $closingTagLength = strlen(self::CLOSING_SCRIPT_TAG);

        return self::sanitize(substr($script, 0, -$closingTagLength))
            . substr($script, -$closingTagLength);
    }
}
