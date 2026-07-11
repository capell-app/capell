<?php

declare(strict_types=1);

use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Facades\Http;

it('fetches and caches plugin packages', function (): void {
    config()->set('capell.plugins_source_url', 'https://plugin.capell.test/packages.json');

    Http::fake([
        config('capell.plugins_source_url') => Http::response([
            'packages' => [
                ['name' => 'package-one'],
                ['name' => 'package-two'],
            ],
        ], 200),
    ]);

    $fetcher = resolve(PluginPackagesFetcher::class);

    $packages = $fetcher->fetch(force: true);
    expect($packages)->toHaveCount(2);
    expect($packages->pluck('name')->all())->toBe(['package-one', 'package-two']);

    // Ensure cached value exists
    $cached = $fetcher->getCached();
    expect($cached)->toHaveCount(2);
});

it('fetches plugin packages when response is a flat array', function (): void {
    config()->set('capell.plugins_source_url', 'https://plugin.capell.test/packages.json');

    Http::fake([
        config('capell.plugins_source_url') => Http::response([
            ['name' => 'package-three'],
            ['name' => 'package-four'],
        ], 200),
    ]);

    $fetcher = resolve(PluginPackagesFetcher::class);

    $packages = $fetcher->fetch(force: true);
    expect($packages)->toHaveCount(2);
    expect($packages->pluck('name')->all())->toBe(['package-three', 'package-four']);
});

it('handles failed responses gracefully', function (): void {
    Http::fake([
        config('capell.plugins_source_url') => Http::response(null, 500),
    ]);

    $fetcher = resolve(PluginPackagesFetcher::class);
    $packages = $fetcher->fetch(force: true);
    expect($packages)->toHaveCount(0);
});

it('rejects non-https plugin package source urls before sending a request', function (): void {
    config()->set('capell.plugins_source_url', 'http://plugin.capell.test/packages.json');

    Http::fake();

    $packages = resolve(PluginPackagesFetcher::class)->fetch(force: true);

    expect($packages)->toHaveCount(0);
    Http::assertNothingSent();
});

it('rejects localhost plugin package source urls before sending a request', function (string $url): void {
    config()->set('capell.plugins_source_url', $url);

    Http::fake();

    $packages = resolve(PluginPackagesFetcher::class)->fetch(force: true);

    expect($packages)->toHaveCount(0);
    Http::assertNothingSent();
})->with([
    'localhost' => 'https://localhost/packages.json',
    'loopback' => 'https://127.0.0.1/packages.json',
    'private' => 'https://10.0.0.5/packages.json',
    'metadata' => 'https://169.254.169.254/packages.json',
]);

it('rejects oversized plugin package responses before decoding json', function (): void {
    config()->set('capell.plugins_source_url', 'https://plugin.capell.test/packages.json');

    Http::fake([
        'https://plugin.capell.test/packages.json' => Http::response(str_repeat('a', 1048577), 200),
    ]);

    $packages = resolve(PluginPackagesFetcher::class)->fetch(force: true);

    expect($packages)->toHaveCount(0);
});
