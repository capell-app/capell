<?php

declare(strict_types=1);

use Capell\Frontend\Support\Routing\PublicUrlResolver;
use Illuminate\Support\Facades\URL;

it('prefers the active site root over the application fallback URL', function (): void {
    config()->set('app.url', 'http://127.0.0.1:8099');
    URL::forceRootUrl('https://northstar.example');

    expect(resolve(PublicUrlResolver::class)->to('/services'))
        ->toBe('https://northstar.example/services');

    URL::forceRootUrl(null);
});
