<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Security;

use Capell\Core\Support\Security\SignedUrlCanonicalizer;
use Capell\Frontend\Contracts\UrlSignatureVerifierInterface;

class FrontendUrlSignatureService implements UrlSignatureVerifierInterface
{
    public function __construct(private readonly string $secret) {}

    public function sign(string $url): string
    {
        return hash_hmac('sha256', SignedUrlCanonicalizer::canonicalize($url), $this->secret);
    }

    public function verify(string $url, string $signature): bool
    {
        return hash_equals($this->sign($url), $signature);
    }

    public function checkSignedUrl(string $fullUrl, string $signature): bool
    {
        return $this->verify($fullUrl, $signature);
    }
}
