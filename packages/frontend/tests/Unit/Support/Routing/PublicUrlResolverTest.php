<?php

declare(strict_types=1);

use Capell\Frontend\Support\Routing\PublicUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

it('prefers the active site root over the application fallback URL', function (): void {
    config()->set('app.url', 'http://127.0.0.1:8099');
    URL::useOrigin('https://northstar.example');

    expect(resolve(PublicUrlResolver::class)->to('/services'))
        ->toBe('https://northstar.example/services');

    URL::useOrigin(null);
});

it('rejects a private request origin when the configured application origin is public', function (): void {
    config()->set('app.url', 'https://capell.app');
    app()->instance('request', Request::create('http://127.0.0.1:20800/extensions/themes'));

    expect(resolve(PublicUrlResolver::class)->to('/extensions/themes'))
        ->toBe('https://capell.app/extensions/themes');
});

it('rejects internal request origins that PHP range flags treat as public', function (string $requestOrigin): void {
    config()->set('app.url', 'https://capell.app');
    app()->instance('request', Request::create($requestOrigin . '/extensions/themes'));

    expect(resolve(PublicUrlResolver::class)->to('/extensions/themes'))
        ->toBe('https://capell.app/extensions/themes');
})->with([
    'carrier-grade NAT' => 'http://100.64.0.1:20800',
    'benchmarking' => 'http://198.18.0.1',
    'IPv4-mapped loopback' => 'http://[::ffff:127.0.0.1]:20800',
]);

it('keeps a public request origin when the configured application origin is public', function (): void {
    config()->set('app.url', 'https://capell.app');
    app()->instance('request', Request::create('http://93.184.216.34/extensions/themes'));

    expect(resolve(PublicUrlResolver::class)->to('/extensions/themes'))
        ->toBe('http://93.184.216.34/extensions/themes');
});

it('preserves a private request origin for local applications', function (): void {
    config()->set('app.url', 'http://capell.test');
    app()->instance('request', Request::create('http://127.0.0.1:20800/extensions/themes'));

    expect(resolve(PublicUrlResolver::class)->to('/extensions/themes'))
        ->toBe('http://127.0.0.1:20800/extensions/themes');
});
