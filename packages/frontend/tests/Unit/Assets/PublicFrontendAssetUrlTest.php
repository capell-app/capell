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
