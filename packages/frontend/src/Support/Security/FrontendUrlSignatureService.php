<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

use Capell\Frontend\Contracts\UrlSignatureVerifierInterface;

class FrontendUrlSignatureService implements UrlSignatureVerifierInterface
{
    public function __construct(private readonly string $secret) {}

    public function sign(string $url): string
    {
        return hash_hmac('sha256', $this->canonicalUrl($url), $this->secret);
    }

    public function verify(string $url, string $signature): bool
    {
        return hash_equals($this->sign($url), $signature);
    }

    public function checkSignedUrl(string $fullUrl, string $signature): bool
    {
        return $this->verify($fullUrl, $signature);
    }

    private function canonicalUrl(string $url): string
    {
        $urlParts = parse_url($url);

        if (! is_array($urlParts)) {
            return $url;
        }

        $scheme = is_string($urlParts['scheme'] ?? null) && $urlParts['scheme'] !== '' ? $urlParts['scheme'] : 'https';
        $host = is_string($urlParts['host'] ?? null) ? $urlParts['host'] : '';
        $port = isset($urlParts['port']) && is_int($urlParts['port']) ? ':' . $urlParts['port'] : '';
        $path = is_string($urlParts['path'] ?? null) ? $urlParts['path'] : '';

        $query = [];
        if (is_string($urlParts['query'] ?? null) && $urlParts['query'] !== '') {
            $query = $this->queryParametersWithoutSignature($urlParts['query']);
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
    private function queryParametersWithoutSignature(string $queryString): array
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
