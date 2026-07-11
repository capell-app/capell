<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

interface UrlSignatureVerifierInterface
{
    public function checkSignedUrl(string $fullUrl, string $signature): bool;
}
