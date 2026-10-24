<?php

declare(strict_types=1);

use Capell\Frontend\Support\Error\ErrorPageFallbackManifest;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Error\ErrorPageManifestLocation;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

afterEach(function (): void {
    File::delete(resolve(ErrorPageFallbackManifestStore::class)->path());
});

it('reads the current manifest for each fallback operation without crossing host branding', function (): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);

    $store->write([
        'version' => 1,
        'default' => fallbackBranding('Default A'),
        'hosts' => [
            'alpha.test' => fallbackBranding('Alpha A'),
            'beta.test' => fallbackBranding('Beta A'),
        ],
    ]);

    expect(ErrorPageFallbackManifest::forHost('alpha.test', '500'))
        ->toBe([
            'logo_url' => 'https://cdn.test/Alpha A.svg',
            'copy' => ['headline' => 'Alpha A headline', 'description' => 'Alpha A description'],
        ])
        ->and(ErrorPageFallbackManifest::forHost('beta.test', '500')['logo_url'])
        ->toBe('https://cdn.test/Beta A.svg');

    $store->write([
        'version' => 1,
        'default' => fallbackBranding('Default B'),
        'hosts' => [
            'alpha.test' => fallbackBranding('Alpha B'),
            'gamma.test' => fallbackBranding('Gamma B'),
        ],
    ]);

    expect(ErrorPageFallbackManifest::forHost('alpha.test', '500'))
        ->toBe([
            'logo_url' => 'https://cdn.test/Alpha B.svg',
            'copy' => ['headline' => 'Alpha B headline', 'description' => 'Alpha B description'],
        ])
        ->and(ErrorPageFallbackManifest::forHost('beta.test', '500'))
        ->toBe([
            'logo_url' => 'https://cdn.test/Default B.svg',
            'copy' => ['headline' => 'Default B headline', 'description' => 'Default B description'],
        ])
        ->and(ErrorPageFallbackManifest::forHost('gamma.test', '500')['logo_url'])
        ->toBe('https://cdn.test/Gamma B.svg');
});

it('makes store updates immediately visible to the fallback reader', function (): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);

    $store->setDefault('https://cdn.test/default-a.svg', [
        500 => ['headline' => 'Default A', 'description' => 'Default A description'],
    ]);
    $store->setHost('alpha.test', 'https://cdn.test/alpha-a.svg', [
        500 => ['headline' => 'Alpha A', 'description' => 'Alpha A description'],
    ]);

    expect(ErrorPageFallbackManifest::logoUrl('alpha.test'))->toBe('https://cdn.test/alpha-a.svg')
        ->and(ErrorPageFallbackManifest::copy('alpha.test', '500'))->toBe([
            'headline' => 'Alpha A',
            'description' => 'Alpha A description',
        ]);

    $store->setDefault('https://cdn.test/default-b.svg', [
        500 => ['headline' => 'Default B', 'description' => 'Default B description'],
    ]);
    $store->setHost('alpha.test', 'https://cdn.test/alpha-b.svg', [
        500 => ['headline' => 'Alpha B', 'description' => 'Alpha B description'],
    ]);

    expect(ErrorPageFallbackManifest::forHost('alpha.test', '500'))->toBe([
        'logo_url' => 'https://cdn.test/alpha-b.svg',
        'copy' => ['headline' => 'Alpha B', 'description' => 'Alpha B description'],
    ])->and(ErrorPageFallbackManifest::forHost('unknown.test', '500'))->toBe([
        'logo_url' => 'https://cdn.test/default-b.svg',
        'copy' => ['headline' => 'Default B', 'description' => 'Default B description'],
    ]);
});

/** @return array{logo_url: string, copy: array<int, array{headline: string, description: string}>} */
function fallbackBranding(string $name): array
{
    return [
        'logo_url' => 'https://cdn.test/' . $name . '.svg',
        'copy' => [
            500 => [
                'headline' => $name . ' headline',
                'description' => $name . ' description',
            ],
        ],
    ];
}

it('reads and writes both manifests in the configured directory', function (): void {
    $directory = storage_path('framework/testing/error-page-manifest-location');
    config()->set(ErrorPageManifestLocation::CONFIG_KEY, $directory . '/');

    try {
        $store = resolve(ErrorPageFallbackManifestStore::class);
        $store->write(['version' => 1, 'default' => fallbackBranding('Relocated')]);

        expect($store->path())->toBe($directory . DIRECTORY_SEPARATOR . 'capell-error-pages-fallback.json')
            ->and(resolve(ErrorPageManifestStore::class)->path())->toBe($directory . DIRECTORY_SEPARATOR . 'capell-error-pages.json')
            ->and(File::exists(storage_path('framework/capell-error-pages-fallback.json')))->toBeFalse()
            ->and(ErrorPageFallbackManifest::forHost('any.test', '500')['logo_url'])->toBe('https://cdn.test/Relocated.svg');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('preserves per-status legacy fallback without merging partial branded copy', function (): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);
    $store->setDefault('/default.svg', [
        404 => ['headline' => 'Default missing', 'description' => 'Default description'],
        500 => ['headline' => 'Default error', 'description' => 'Default description'],
    ]);
    $store->setHost('alpha.test', '', [500 => ['headline' => 'Alpha error']]);

    expect(ErrorPageFallbackManifest::forHost('alpha.test', '404'))->toBe([
        'logo_url' => '/default.svg',
        'copy' => ['headline' => 'Default missing', 'description' => 'Default description'],
    ])->and(ErrorPageFallbackManifest::forHost('alpha.test', '500'))->toBe([
        'logo_url' => '/default.svg',
        'copy' => ['headline' => 'Alpha error', 'description' => null],
    ]);
});

it('fails closed on malformed route branding without querying the database', function (): void {
    resolve(ErrorPageFallbackManifestStore::class)->write([
        'version' => 2,
        'sites' => [
            'bad-site' => 'invalid',
            'bad-domains' => ['domains' => 'invalid'],
            'bad-entry' => ['domains' => [false, ['scheme' => [], 'domain' => []]]],
        ],
    ]);
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(ErrorPageFallbackManifest::forHost('example.test', '404'))->toBe(['logo_url' => null, 'copy' => null])
        ->and($queries)->toBe(0);
});

it('replaces previous route ownership by domain id and canonical address', function (int $old, int $new, bool $sameId): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);
    $route = ['site_domain_id' => 12, 'scheme' => 'https', 'port' => 443, 'domain' => 'shared.test', 'path' => '/docs'];
    $store->replaceSite($old, [array_merge($route, fallbackBranding('OLD OWNER'))]);
    $replacement = array_merge($route, [
        'site_domain_id' => $sameId ? 12 : 99,
        'port' => null, 'domain' => 'SHARED.test', 'path' => '/docs/',
    ], fallbackBranding('NEW OWNER'));
    $store->replaceSite($new, [$replacement]);

    expect(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 443, '/docs/missing')['copy']['headline'] ?? null)
        ->toBe('NEW OWNER headline');
    // Regenerating the former owner afterwards must not resurrect its branding.
    $store->replaceSite($old, []);
    expect(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 443, '/docs/missing')['copy']['headline'] ?? null)
        ->toBe('NEW OWNER headline');
})->with([[1, 2, true], [2, 1, true], [1, 2, false], [2, 1, false]]);

it('removes the former address of a transferred domain id', function (): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);
    $route = ['site_domain_id' => 12, 'scheme' => 'https', 'port' => null, 'domain' => 'old.test', 'path' => '/'];
    $store->replaceSite(1, [array_merge($route, fallbackBranding('OLD OWNER'))]);
    $store->replaceSite(2, [array_merge($route, ['domain' => 'new.test'], fallbackBranding('NEW OWNER'))]);

    expect(ErrorPageFallbackManifest::forHost('old.test', '500')['copy'])->toBeNull()
        ->and(ErrorPageFallbackManifest::forHost('new.test', '500')['copy']['headline'] ?? null)->toBe('NEW OWNER headline');
});

it('ignores unversioned generated branding until routes are regenerated including old worker rewrites', function (): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);
    $legacy = ['default' => fallbackBranding('WRONG OWNER'), 'hosts' => ['shared.test' => fallbackBranding('OLD OWNER')]];
    foreach ([false, true] as $converted) {
        if ($converted) {
            $store->replaceSite(2, [[
                'scheme' => 'https', 'port' => 8443, 'domain' => 'shared.test', 'path' => '/',
                ...fallbackBranding('NEW OWNER'),
            ]]);
            expect(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 8443)['copy']['headline'] ?? null)->toBe('NEW OWNER headline');
        }

        $store->write($legacy);
        expect(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 8443))->toBe(['logo_url' => null, 'copy' => null])
            ->and(ErrorPageFallbackManifest::forHost('unknown.test', '500'))->toBe(['logo_url' => null, 'copy' => null]);
    }
});

it('round trips explicit branding before and after generation and subsequent regeneration', function (bool $explicitFirst): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);
    $generate = fn () => $store->replaceSite(1, [[
        'scheme' => 'https', 'port' => 8443, 'domain' => 'shared.test', 'path' => '/docs',
        ...fallbackBranding('GENERATED'),
    ], [
        'scheme' => 'https', 'port' => 443, 'domain' => 'route.test', 'path' => '/docs',
        ...fallbackBranding('ROUTE'),
    ]]);
    $configure = function () use ($store): void {
        $host = fallbackBranding('HOST');
        $default = fallbackBranding('DEFAULT');
        $store->setHost('SHARED.test', $host['logo_url'], $host['copy']);
        $store->setDefault($default['logo_url'], $default['copy']);
    };
    if ($explicitFirst) {
        $configure();
        $generate();
    } else {
        $generate();
        $configure();
    }

    for ($round = 0; $round < 2; $round++) {
        expect(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 8443, '/docs/missing')['copy']['headline'] ?? null)->toBe('HOST headline')
            ->and(ErrorPageFallbackManifest::forHost('shared.test', '500')['logo_url'])->toBe('https://cdn.test/HOST.svg')
            ->and(ErrorPageFallbackManifest::forHost('route.test', '500', 'https', 443, '/docs/missing')['copy']['headline'] ?? null)->toBe('ROUTE headline')
            ->and(ErrorPageFallbackManifest::forHost('route.test', '500', 'https', 8443, '/docs')['copy']['headline'] ?? null)->toBe('DEFAULT headline')
            ->and(ErrorPageFallbackManifest::forHost('route.test', '500', 'https', 443, '/outside')['logo_url'])->toBe('https://cdn.test/DEFAULT.svg')
            ->and(ErrorPageFallbackManifest::forHost('unrelated.test', '500')['copy']['headline'] ?? null)->toBe('DEFAULT headline');
        $generate();
    }
})->with([true, false]);
