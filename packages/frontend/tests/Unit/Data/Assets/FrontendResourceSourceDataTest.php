<?php

declare(strict_types=1);

use Capell\Frontend\Data\Assets\ExternalResourceSourceData;
use Capell\Frontend\Data\Assets\PublicResourceSourceData;
use Capell\Frontend\Data\Assets\ViteResourceSourceData;
use Capell\Frontend\Enums\CrossOrigin;
use Capell\Frontend\Enums\ReferrerPolicy;

it('preserves a valid external URL and security attributes exactly', function (): void {
    $source = new ExternalResourceSourceData(
        httpsUrl: 'https://cdn.example.com/library.min.js?v=4.2.1',
        integrity: 'sha384-YWJjZA== sha512-ZGVmZw==',
        referrerPolicy: ReferrerPolicy::NoReferrer,
    );

    expect($source->httpsUrl)->toBe('https://cdn.example.com/library.min.js?v=4.2.1')
        ->and($source->integrity)->toBe('sha384-YWJjZA== sha512-ZGVmZw==')
        ->and($source->crossOrigin)->toBe(CrossOrigin::Anonymous)
        ->and($source->referrerPolicy)->toBe(ReferrerPolicy::NoReferrer);
});

it('accepts every supported sri algorithm', function (string $integrity): void {
    expect(new ExternalResourceSourceData('https://cdn.example.com/app.js', $integrity))
        ->integrity->toBe($integrity);
})->with(['sha256-YWJjZA==', 'sha384-YWJjZA==', 'sha512-YWJjZA==']);

it('rejects unsafe external URLs', function (string $url): void {
    new ExternalResourceSourceData($url);
})->with([
    'http' => 'http://cdn.example.com/app.js',
    'protocol relative' => '//cdn.example.com/app.js',
    'credentials' => 'https://user:secret@cdn.example.com/app.js',
    'fragment' => 'https://cdn.example.com/app.js#payload',
    'data' => 'data:text/javascript,alert(1)',
    'javascript' => 'javascript:alert(1)',
    'relative' => '/vendor/app.js',
])->throws(InvalidArgumentException::class);

it('rejects malformed sri values', function (string $integrity): void {
    new ExternalResourceSourceData('https://cdn.example.com/app.js', $integrity);
})->with([
    'unsupported algorithm' => 'md5-YWJjZA==',
    'missing digest' => 'sha384-',
    'invalid base64' => 'sha384-not_base64!',
    'partially valid list' => 'sha384-YWJjZA== invalid',
])->throws(InvalidArgumentException::class);

it('rejects invalid public and vite paths', function (): void {
    expect(fn (): PublicResourceSourceData => new PublicResourceSourceData('https://example.com/app.js'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): PublicResourceSourceData => new PublicResourceSourceData('../private/app.js'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ViteResourceSourceData => new ViteResourceSourceData('/absolute/app.js'))
        ->toThrow(InvalidArgumentException::class);
});
