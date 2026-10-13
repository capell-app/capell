<?php

declare(strict_types=1);

use Capell\Frontend\Support\Assets\PublicFrontendAssetUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;

it('resolves public frontend assets against the current request origin', function (): void {
    app()->instance('request', Request::create('http://127.0.0.1:8000/recovery-alignment'));

    $resolver = resolve(PublicFrontendAssetUrl::class);

    expect($resolver->to('css/theme.css'))->toBe('http://127.0.0.1:8000/css/theme.css')
        ->and($resolver->to('/images/equidynamics/logo.png?version=1'))->toBe('http://127.0.0.1:8000/images/equidynamics/logo.png?version=1');
});

it('keeps absolute and special urls unchanged', function (string $url): void {
    app()->instance('request', Request::create('http://127.0.0.1:8000/'));

    expect(resolve(PublicFrontendAssetUrl::class)->to($url))->toBe($url);
})->with([
    'https asset' => ['https://cdn.example.test/theme.css'],
    'fragment' => ['#section'],
    'data image url' => ['data:image/svg+xml;base64,PHN2Zy8+'],
]);

it('rejects unsafe asset urls', function (string $url): void {
    app()->instance('request', Request::create('http://127.0.0.1:8000/'));

    expect(resolve(PublicFrontendAssetUrl::class)->to($url))->toBe('');
})->with([
    'javascript url' => ['javascript:alert(1)'],
    'data html url' => ['data:text/html,<script>alert(1)</script>'],
    'protocol relative asset' => ['//cdn.example.test/theme.css'],
    'null byte' => ["css/theme.css\0.jpg"],
]);

it('exposes a Blade directive for theme views', function (): void {
    app()->instance('request', Request::create('http://127.0.0.1:8000/'));

    $html = Blade::render('<link href="@frontendAsset(\'css/theme.css\')" rel="stylesheet">');

    expect($html)->toContain('href="http://127.0.0.1:8000/css/theme.css"');
});

it('keeps front controllers out of public asset paths while retaining application mounts', function (string $uri, string $script, string $expectedOrigin): void {
    $request = Request::create($uri, server: [
        'SCRIPT_FILENAME' => '/srv/capell/public/index.php',
        'SCRIPT_NAME' => $script,
        'PHP_SELF' => parse_url($uri, PHP_URL_PATH),
    ]);
    app()->instance('request', $request);

    $path = 'vendor/example/theme.css?version=1';
    expect(resolve(PublicFrontendAssetUrl::class)->to($path))->toBe($expectedOrigin . '/' . $path);

    $html = Blade::render('<link href="@frontendAsset(\'vendor/example/theme.css?version=1\')" rel="stylesheet">');
    expect($html)->toContain('href="' . $expectedOrigin . '/' . $path . '"');
})->with([
    'root front controller' => ['https://example.test/index.php/guide', '/index.php', 'https://example.test'],
    'mounted front controller' => ['https://example.test/cms/index.php/guide', '/cms/index.php', 'https://example.test/cms'],
    'ordinary root request' => ['https://example.test/guide', '/index.php', 'https://example.test'],
    'ordinary mounted request' => ['https://example.test:8443/cms/guide', '/cms/index.php', 'https://example.test:8443/cms'],
]);
