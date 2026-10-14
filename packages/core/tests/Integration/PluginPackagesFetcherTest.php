<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

it('reads current catalogue identities tiers versions and all pages', function (): void {
    config(['capell.plugins_source_url' => 'https://catalogue.capell.test/extensions']);
    Http::fake([
        'https://catalogue.capell.test/extensions' => Http::response([
            'data' => [[
                'composer_name' => 'capell-app/address', 'latest_version' => '1.1.0-beta.45',
                'product_tier' => 'premium', 'is_paid' => false, 'kind' => 'field',
                'slug' => 'address', 'install_state' => 'free_available',
                'install_eligibility' => ['state' => 'free_available', 'can_install' => true],
                'purchase_url' => 'https://capell.app/marketplace/address',
                'dependencies' => ['requires' => ['capell-app/admin']],
                'manifest' => ['description' => 'Postal addresses.'],
            ]],
            'meta' => ['last_page' => 2],
        ]),
        'https://catalogue.capell.test/extensions?page=2' => Http::response([
            'data' => [['composer_name' => 'capell-app/seo-suite', 'product_tier' => 'premium', 'kind' => 'tool']],
            'meta' => ['last_page' => 2],
        ]),
    ]);
    $packages = resolve(PluginPackagesFetcher::class)->fetch(force: true)->keyBy('name');

    expect($packages->keys()->all())->toBe(['capell-app/address', 'capell-app/seo-suite'])
        ->and($packages->pluck('version', 'name')->get('capell-app/address'))->toBe('1.1.0-beta.45')
        ->and($packages->pluck('tier', 'name')->all())->toBe(['capell-app/address' => 'premium', 'capell-app/seo-suite' => 'premium'])
        ->and($packages->get('capell-app/address'))->toMatchArray([
            'isPaid' => false, 'slug' => 'address', 'installState' => 'free_available',
            'installEligibility' => ['state' => 'free_available', 'can_install' => true],
            'purchaseUrl' => 'https://capell.app/marketplace/address',
        ])
        ->and($packages->pluck('requirements', 'name')->get('capell-app/address'))->toBe(['capell-app/admin']);
});

it('does not reuse a catalogue cached for a different source', function (): void {
    config(['capell.plugins_source_url' => 'https://old.capell.test/packages.json']);
    Http::fake(['*' => Http::response(['packages' => [['name' => 'old/package']]])]);
    $fetcher = resolve(PluginPackagesFetcher::class);
    $fetcher->fetch(force: true);

    config(['capell.plugins_source_url' => 'https://current.capell.test/packages.json']);
    expect($fetcher->getCached())->toBeEmpty();
});

it('does not cache a partial catalogue when a later page fails', function (): void {
    config(['capell.plugins_source_url' => 'https://catalogue.capell.test/extensions']);
    Http::fake([
        'https://catalogue.capell.test/extensions' => Http::response([
            'data' => [['composer_name' => 'capell-app/blog']], 'meta' => ['last_page' => 2],
        ]),
        'https://catalogue.capell.test/extensions?page=2' => Http::response(null, 403),
    ]);
    $fetcher = resolve(PluginPackagesFetcher::class);

    expect($fetcher->fetch(force: true))->toBeEmpty()
        ->and($fetcher->getCached())->toBeEmpty();
});

it('caches empty catalogues for the configured ttl', function (): void {
    config(['capell.plugins_source_url' => 'https://catalogue.capell.test/extensions', 'capell.plugins_cache_ttl' => 60]);
    Http::fake(['*' => Http::response(['packages' => []])]);
    $fetcher = resolve(PluginPackagesFetcher::class);
    expect($fetcher->fetch())->toBeEmpty()->and($fetcher->fetch())->toBeEmpty();
    Http::assertSentCount(1);

    $this->travel(61)->seconds();
    CapellCore::flushLocalCache();
    expect($fetcher->fetch())->toBeEmpty();
    Http::assertSentCount(2);
});

it('bounds page fetches without retrying and allows recovery after an offline cooldown', function (): void {
    config(['capell.plugins_source_url' => 'https://catalogue.capell.test/extensions']);
    $budgets = [];
    $state = new stdClass;
    $state->available = false;
    Http::fake(function ($request, array $options) use (&$budgets, $state) {
        $budgets[] = [$options['connect_timeout'], $options['timeout']];

        return $state->available
            ? Http::response(['packages' => [['name' => 'vendor/recovered']]])
            : Http::response(null, 503, ['Retry-After' => '60']);
    });
    $fetcher = resolve(PluginPackagesFetcher::class);
    expect($fetcher->fetchWithinBudget(2))->toBeEmpty()
        ->and($fetcher->fetch())->toBeEmpty();
    Http::assertSentCount(1);
    expect($budgets[0][0])->toBeLessThanOrEqual(1)
        ->and($budgets[0][1])->toBeLessThanOrEqual(2);

    $state->available = true;
    $this->travel(31)->seconds();
    CapellCore::flushLocalCache();
    expect($fetcher->fetch()->pluck('name')->all())->toBe(['vendor/recovered']);
});

it('honours disabled catalogue caching and forced refreshes', function (): void {
    config(['capell.plugins_source_url' => 'https://catalogue.capell.test/extensions']);
    Http::fake(['*' => Http::response(['packages' => []])]);
    $fetcher = resolve(PluginPackagesFetcher::class);
    $fetcher->fetch();
    $fetcher->fetch(force: true);
    Http::assertSentCount(2);

    config(['capell.disable_cache' => true]);
    $fetcher->fetch();
    $fetcher->fetch();
    Http::assertSentCount(4);
});

it('shares the page time budget across pagination without caching partial data', function (): void {
    config(['capell.plugins_source_url' => 'https://catalogue.capell.test/extensions']);
    Http::fake(function () {
        Sleep::usleep(500000);

        return Http::response([
            'data' => [['composer_name' => 'vendor/first-page']],
            'meta' => ['last_page' => 2],
        ]);
    });
    $fetcher = resolve(PluginPackagesFetcher::class);

    expect($fetcher->fetchWithinBudget(0.25))->toBeEmpty()
        ->and($fetcher->getCached())->toBeEmpty();
    Http::assertSentCount(1);
});
