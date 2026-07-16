<?php

declare(strict_types=1);

use Capell\Frontend\Support\Routing\PublicUrlResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

it('prefers the active site root over the application fallback URL', function (): void {
    config()->set('app.url', 'http://127.0.0.1:8099');
    URL::forceRootUrl('https://northstar.example');

    expect(resolve(PublicUrlResolver::class)->to('/services'))
        ->toBe('https://northstar.example/services');

    URL::forceRootUrl(null);
});

it('rejects a private request origin when the configured application origin is public', function (): void {
    config()->set('app.url', 'https://capell.app');
    app()->instance('request', Request::create('http://127.0.0.1:20800/extensions/themes'));

    expect(resolve(PublicUrlResolver::class)->to('/extensions/themes'))
        ->toBe('https://capell.app/extensions/themes');
});

it('preserves a private request origin for local applications', function (): void {
    config()->set('app.url', 'http://capell.test');
    app()->instance('request', Request::create('http://127.0.0.1:20800/extensions/themes'));

    expect(resolve(PublicUrlResolver::class)->to('/extensions/themes'))
        ->toBe('http://127.0.0.1:20800/extensions/themes');
});
