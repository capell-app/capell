<?php

declare(strict_types=1);

use Capell\Core\Models\SiteDomain;
use Capell\Frontend\Contracts\UrlSignatureVerifierInterface;
use Capell\Frontend\Support\Context\FrontendContext;

describe('FrontendContext::isValidSignature', function (): void {
    it('returns false if signature is null, empty, or zero', function (): void {
        $domain = Mockery::mock(SiteDomain::class);
        $domain->shouldReceive('getAttribute')
            ->with('full_url')
            ->andReturn('https://example.com');

        expect(FrontendContext::isValidSignature($domain, '/foo', null))->toBeFalse();
        expect(FrontendContext::isValidSignature($domain, '/foo', ''))->toBeFalse();
        expect(FrontendContext::isValidSignature($domain, '/foo', '0'))->toBeFalse();
    });

    it('delegates to UrlSignatureVerifierInterface', function (): void {
        $domain = Mockery::mock(SiteDomain::class);
        $domain->shouldReceive('getAttribute')
            ->with('full_url')
            ->andReturn('https://example.com');

        $verifier = Mockery::mock(UrlSignatureVerifierInterface::class);
        $verifier->shouldReceive('checkSignedUrl')
            ->with('https://example.com/foo', 'sig123')
            ->once()
            ->andReturnTrue();

        app()->instance(UrlSignatureVerifierInterface::class, $verifier);

        expect(FrontendContext::isValidSignature($domain, '/foo', 'sig123'))->toBeTrue();
    });
});
