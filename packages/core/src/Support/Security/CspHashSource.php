<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

/**
 * Builds and validates Content Security Policy hash sources.
 */
final class CspHashSource
{
    private const string PATTERN = "/\\A'sha(?:256|384|512)-[A-Za-z0-9+\\/]+={0,2}'\\z/";

    /**
     * The `'sha256-…'` source that authorises an inline element with exactly this text content.
     */
    public static function forContent(string $content): string
    {
        return "'sha256-" . base64_encode(hash('sha256', $content, true)) . "'";
    }

    public static function isValid(mixed $source): bool
    {
        return is_string($source) && preg_match(self::PATTERN, $source) === 1;
    }
}
