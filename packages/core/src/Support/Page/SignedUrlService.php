<?php

declare(strict_types=1);

namespace Capell\Core\Support\Page;

use Capell\Core\Support\Security\SignedUrlCanonicalizer;
use Exception;

final class SignedUrlService
{
    public static function signedDraft(string $url, ?int $draftId): string
    {
        $urlParts = parse_url($url);

        throw_if(! isset($urlParts['host']) || $urlParts['host'] === '', Exception::class, 'Invalid URL: ' . $url);

        $urlParts['path'] = ($urlParts['path'] ?? '') . '{' . $draftId . '}';
        $unsignedUrl = SignedUrlCanonicalizer::fromParts($urlParts);
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
}
