<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteDomains\NormalizeSiteDomainInputAction;

it('normalizes default ports out of persisted site domain input', function (string $url): void {
    $input = NormalizeSiteDomainInputAction::run($url);

    expect($input->port)->toBeNull();
})->with([
    'implicit HTTP port' => 'http://example.test',
    'explicit HTTP port' => 'http://example.test:80',
    'implicit HTTPS port' => 'https://example.test',
    'explicit HTTPS port' => 'https://example.test:443',
]);

it('preserves non-default ports and canonicalizes IPv6 hosts', function (): void {
    $input = NormalizeSiteDomainInputAction::run('http://[2001:0db8:0:0:0:0:0:1]:8081/en/');

    expect($input->scheme)->toBe('http')
        ->and($input->host)->toBe('2001:db8::1')
        ->and($input->port)->toBe(8081)
        ->and($input->mountPath)->toBe('/en');
});

it('rejects ports outside the valid range', function (string $url): void {
    NormalizeSiteDomainInputAction::run($url);
})->with([
    'zero' => 'http://example.test:0',
    'too high' => 'http://example.test:65536',
])->throws(InvalidArgumentException::class);
