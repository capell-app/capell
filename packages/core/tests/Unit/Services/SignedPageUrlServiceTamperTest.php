<?php

declare(strict_types=1);

use Capell\Core\Support\Page\SignedUrlService;
use Capell\Frontend\Support\Security\FrontendUrlSignatureService;

it('invalidates signature when URL is tampered', function (): void {
    $original = SignedUrlService::signedDraft('https://example.com/page?x=1', 42);
    $verifier = new FrontendUrlSignatureService((string) config('app.key'));

    $originalQuery = signedPageUrlServiceTamperQueryParameters((string) (parse_url($original, PHP_URL_QUERY) ?? ''));
    $originalSignature = $originalQuery['signature'] ?? '';
    unset($originalQuery['signature']);

    $unsignedOriginalUrl = sprintf(
        '%s://%s%s?%s',
        parse_url($original, PHP_URL_SCHEME),
        parse_url($original, PHP_URL_HOST),
        parse_url($original, PHP_URL_PATH),
        http_build_query($originalQuery),
    );

    expect($verifier->checkSignedUrl($unsignedOriginalUrl, $originalSignature))->toBeTrue();

    $tampered = preg_replace('/x=1/', 'x=2', $original) ?? $original;

    $query = signedPageUrlServiceTamperQueryParameters((string) (parse_url($tampered, PHP_URL_QUERY) ?? ''));
    $signature = $query['signature'] ?? '';
    unset($query['signature']);

    $unsignedTamperedUrl = sprintf(
        '%s://%s%s?%s',
        parse_url($tampered, PHP_URL_SCHEME),
        parse_url($tampered, PHP_URL_HOST),
        parse_url($tampered, PHP_URL_PATH),
        http_build_query($query),
    );

    expect($signature)->toBeString()
        ->and($verifier->checkSignedUrl($unsignedTamperedUrl, $signature))->toBeFalse();
});

/**
 * @return array<string, string>
 */
function signedPageUrlServiceTamperQueryParameters(string $queryString): array
{
    $query = [];

    foreach (explode('&', $queryString) as $queryPart) {
        if ($queryPart === '') {
            continue;
        }

        [$encodedKey, $encodedValue] = array_pad(explode('=', $queryPart, 2), 2, '');
        $query[rawurldecode(str_replace('+', ' ', $encodedKey))] = rawurldecode(str_replace('+', ' ', $encodedValue));
    }

    return $query;
}
