<?php

declare(strict_types=1);

use Capell\Frontend\Contracts\UrlSignatureVerifierInterface;
use Capell\Frontend\Support\Security\FrontendUrlSignatureService;

it('signs and verifies urls with deterministic HMAC', function (): void {
    $svc = new FrontendUrlSignatureService('secret');

    $url = 'https://example.com/en/docs?page=2';

    $sig = $svc->sign($url);

    expect($sig)->not()->toBe('')
        ->and($svc->verify($url, $sig))->toBeTrue()
        ->and($svc->verify($url, 'bad'))->toBeFalse();
});

it('resolves the signature verifier from the container', function (): void {
    expect(resolve(UrlSignatureVerifierInterface::class))
        ->toBeInstanceOf(FrontendUrlSignatureService::class);
});
