<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteDomains;

use Capell\Core\Data\SiteDomains\SiteDomainInputData;
use Capell\Core\Data\SiteDomains\SiteOriginData;
use Capell\Core\Data\SiteDomains\SiteRequestTargetData;
use Capell\Core\Support\Url\UrlPathNormalizer;
use InvalidArgumentException;

final class SiteDomainAddressing
{
    public static function inputFromUrl(string $url, bool $wildcardHost = false): SiteDomainInputData
    {
        $parts = parse_url(trim($url));

        throw_unless(is_array($parts), InvalidArgumentException::class, 'The site domain URL is invalid.');

        $scheme = self::normalizeScheme($parts['scheme'] ?? null);
        $host = $wildcardHost ? null : self::normalizeHost($parts['host'] ?? null);
        $port = self::normalizeStoredPort($scheme, $parts['port'] ?? null);
        $mountPath = self::normalizePath($parts['path'] ?? '/');

        throw_if($scheme === null, InvalidArgumentException::class, 'The site domain URL must include a scheme.');

        throw_if(! $wildcardHost && $host === null, InvalidArgumentException::class, 'The site domain URL must include a host.');

        return new SiteDomainInputData($scheme, $host, $port, $mountPath);
    }

    public static function targetFromUrl(string $url): SiteRequestTargetData
    {
        $parts = parse_url(trim($url));

        throw_unless(is_array($parts), InvalidArgumentException::class, 'The site request URL is invalid.');

        $scheme = self::normalizeScheme($parts['scheme'] ?? null);
        $host = self::normalizeHost($parts['host'] ?? null);

        throw_if($scheme === null || $host === null, InvalidArgumentException::class, 'The site request URL must include a scheme and host.');

        $port = self::effectivePort($scheme, $parts['port'] ?? null);

        return new SiteRequestTargetData(
            origin: new SiteOriginData($scheme, $host, $port),
            path: self::normalizePath(UrlPathNormalizer::stripIndexPhp((string) ($parts['path'] ?? '/'))),
            rawQuery: is_string($parts['query'] ?? null) ? $parts['query'] : '',
        );
    }

    public static function normalizeScheme(mixed $scheme): ?string
    {
        if (! is_string($scheme) || trim($scheme) === '') {
            return null;
        }

        return mb_strtolower(trim($scheme));
    }

    public static function normalizeHost(mixed $host): ?string
    {
        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        $host = mb_strtolower(trim($host, " \t\n\r\0\x0B[]"));
        $packed = @inet_pton($host);

        if ($packed !== false) {
            $canonical = inet_ntop($packed);

            return is_string($canonical) ? mb_strtolower($canonical) : $host;
        }

        throw_if(preg_match('/[\x00-\x20\x7f\/?#@]/', $host) === 1, InvalidArgumentException::class, 'The site domain host is invalid.');

        return rtrim($host, '.');
    }

    public static function normalizeStoredPort(?string $scheme, mixed $port): ?int
    {
        if ($port === null || $port === '') {
            return null;
        }

        $port = filter_var($port, FILTER_VALIDATE_INT);

        throw_if(! is_int($port) || $port < 1 || $port > 65535, InvalidArgumentException::class, 'The site domain port must be between 1 and 65535.');

        return self::defaultPort($scheme) === $port ? null : $port;
    }

    public static function effectivePort(string $scheme, mixed $port): int
    {
        $normalized = self::normalizeStoredPort($scheme, $port);

        if ($normalized !== null) {
            return $normalized;
        }

        return self::defaultPort($scheme)
            ?? throw new InvalidArgumentException('The site request scheme has no default port.');
    }

    public static function defaultPort(?string $scheme): ?int
    {
        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    public static function normalizePath(mixed $path): string
    {
        if (! is_string($path) || trim($path) === '' || $path === '/') {
            return '/';
        }

        $normalized = '/' . trim($path, '/');

        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }

    public static function routingIdentity(
        ?string $scheme,
        ?string $host,
        ?int $port,
        ?string $path,
    ): string {
        $normalizedScheme = self::normalizeScheme($scheme);
        $normalizedHost = self::normalizeHost($host);
        $normalizedPort = self::normalizeStoredPort($normalizedScheme, $port);

        return hash('sha256', implode('|', [
            $normalizedScheme ?? '*',
            $normalizedHost ?? '*',
            $normalizedPort === null ? 'default' : (string) $normalizedPort,
            self::normalizePath($path),
        ]));
    }
}
