<?php

declare(strict_types=1);

namespace Capell\Marketplace\Support;

use RuntimeException;

final class MarketplaceApprovalUrl
{
    public static function validate(string $approvalUrl): string
    {
        $baseUrl = config('capell-marketplace.marketplace.base_url');

        throw_unless(
            is_string($baseUrl)
            && self::isAbsoluteHttpUrl($baseUrl)
            && self::isAbsoluteHttpUrl($approvalUrl),
            RuntimeException::class,
            'Marketplace returned an invalid approval URL.',
        );

        $baseParts = parse_url($baseUrl);
        $approvalParts = parse_url($approvalUrl);

        throw_unless(
            is_array($baseParts)
            && is_array($approvalParts)
            && self::hasNoUserInfo($baseParts)
            && self::hasNoUserInfo($approvalParts)
            && self::sameOrigin($baseParts, $approvalParts),
            RuntimeException::class,
            'Marketplace returned an invalid approval URL.',
        );

        return $approvalUrl;
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * @param  array<string, mixed>  $baseParts
     * @param  array<string, mixed>  $approvalParts
     */
    private static function sameOrigin(array $baseParts, array $approvalParts): bool
    {
        $baseScheme = self::stringPart($baseParts, 'scheme');
        $approvalScheme = self::stringPart($approvalParts, 'scheme');
        $baseHost = self::stringPart($baseParts, 'host');
        $approvalHost = self::stringPart($approvalParts, 'host');

        if ($baseScheme === null || $approvalScheme === null || $baseHost === null || $approvalHost === null) {
            return false;
        }

        return strtolower($baseScheme) === strtolower($approvalScheme)
            && strtolower($baseHost) === strtolower($approvalHost)
            && self::effectivePort($baseParts) === self::effectivePort($approvalParts);
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function stringPart(array $parts, string $key): ?string
    {
        $value = $parts[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function effectivePort(array $parts): ?int
    {
        $port = $parts['port'] ?? null;

        if (is_int($port)) {
            return $port;
        }

        return match (strtolower((string) ($parts['scheme'] ?? ''))) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private static function hasNoUserInfo(array $parts): bool
    {
        return ! array_key_exists('user', $parts)
            && ! array_key_exists('pass', $parts);
    }
}
