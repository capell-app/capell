<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

final class SignedUrlCanonicalizer
{
    public static function canonicalize(string $url): string
    {
        $urlParts = parse_url($url);

        return is_array($urlParts) ? self::fromParts($urlParts) : $url;
    }

    /** @param array<string, mixed> $urlParts */
    public static function fromParts(array $urlParts): string
    {
        $scheme = is_string($urlParts['scheme'] ?? null) && $urlParts['scheme'] !== '' ? $urlParts['scheme'] : 'https';
        $host = is_string($urlParts['host'] ?? null) ? $urlParts['host'] : '';
        $port = isset($urlParts['port']) && is_int($urlParts['port']) ? ':' . $urlParts['port'] : '';
        $path = is_string($urlParts['path'] ?? null) ? $urlParts['path'] : '';

        $query = [];
        if (is_string($urlParts['query'] ?? null) && $urlParts['query'] !== '') {
            $query = self::queryParametersWithoutSignature($urlParts['query']);
            ksort($query);
        }

        $canonical = sprintf('%s://%s%s%s', $scheme, $host, $port, $path);

        return $query === []
            ? $canonical
            : $canonical . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string, string> */
    private static function queryParametersWithoutSignature(string $queryString): array
    {
        $query = [];

        foreach (explode('&', $queryString) as $queryPart) {
            if ($queryPart === '') {
                continue;
            }

            [$encodedKey, $encodedValue] = array_pad(explode('=', $queryPart, 2), 2, '');
            $key = rawurldecode(str_replace('+', ' ', $encodedKey));

            if ($key === 'signature') {
                continue;
            }

            $query[$key] = rawurldecode(str_replace('+', ' ', $encodedValue));
        }

        return $query;
    }
}
