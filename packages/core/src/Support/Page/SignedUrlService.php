<?php

declare(strict_types=1);

namespace Capell\Core\Support\Page;

use Exception;

class SignedUrlService
{
    public static function signedDraft(string $url, ?int $draftId): string
    {
        $urlParts = parse_url($url);

        throw_if(! isset($urlParts['host']) || $urlParts['host'] === '', Exception::class, 'Invalid URL: ' . $url);

        $urlParts['path'] = ($urlParts['path'] ?? '') . '{' . $draftId . '}';
        $unsignedUrl = self::canonicalUrl($urlParts);
        $signature = hash_hmac('sha256', $unsignedUrl, (string) config('app.key'));

        $separator = str_contains($unsignedUrl, '?') ? '&' : '?';

        return $unsignedUrl . $separator . 'signature=' . $signature;
    }

    /**
     * Sign the given parameters and return an array with a signature.
     *
     * @param  array<string, string|int|null>  $params
     * @return array<string, string|int|null>
     */
    public function sign(array $params): array
    {
        $data = http_build_query($params);
        $signature = hash_hmac('sha256', $data, (string) config('app.key'));
        $params['signature'] = $signature;

        return $params;
    }

    /**
     * Verify the signature in the given array of parameters.
     *
     * @param  array<string, string|int|null>  $params
     */
    public function verify(array $params): bool
    {
        $signature = $params['signature'] ?? null;
        unset($params['signature']);
        $data = http_build_query($params);
        $expected = hash_hmac('sha256', $data, (string) config('app.key'));

        return hash_equals($expected, (string) $signature);
    }

    /**
     * @param  array<string, mixed>  $urlParts
     */
    private static function canonicalUrl(array $urlParts): string
    {
        $scheme = is_string($urlParts['scheme'] ?? null) && $urlParts['scheme'] !== '' ? $urlParts['scheme'] : 'https';
        $host = (string) ($urlParts['host'] ?? '');
        $port = isset($urlParts['port']) && is_int($urlParts['port']) ? ':' . $urlParts['port'] : '';
        $path = is_string($urlParts['path'] ?? null) ? $urlParts['path'] : '';

        $query = [];
        if (is_string($urlParts['query'] ?? null) && $urlParts['query'] !== '') {
            $query = self::queryParametersWithoutSignature($urlParts['query']);
            ksort($query);
        }

        $canonical = sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
        if ($query !== []) {
            $canonical .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $canonical;
    }

    /**
     * @return array<string, string>
     */
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
