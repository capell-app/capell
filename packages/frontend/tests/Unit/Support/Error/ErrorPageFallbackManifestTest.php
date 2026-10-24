<?php

declare(strict_types=1);

use Capell\Frontend\Support\Error\ErrorPageFallbackManifest;
use Capell\Frontend\Support\Error\ErrorPageFallbackManifestStore;
use Capell\Frontend\Support\Error\ErrorPageManifestLocation;
use Capell\Frontend\Support\Error\ErrorPageManifestStore;
use Capell\Frontend\Tests\Support\LegacyErrorPageFallbackManifestStore;
use Illuminate\Http\Request;
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
        'explicit_overrides' => [
            'default' => fallbackBranding('Default A'),
            'hosts' => [
                'alpha.test' => fallbackBranding('Alpha A'),
                'beta.test' => fallbackBranding('Beta A'),
            ],
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
        'explicit_overrides' => [
            'default' => fallbackBranding('Default B'),
            'hosts' => [
                'alpha.test' => fallbackBranding('Alpha B'),
                'gamma.test' => fallbackBranding('Gamma B'),
            ],
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
        $store->setDefault('https://cdn.test/Relocated.svg', fallbackBranding('Relocated')['copy']);

        expect($store->path())->toBe($directory . DIRECTORY_SEPARATOR . 'capell-error-pages-fallback.json')
            ->and(resolve(ErrorPageManifestStore::class)->path())->toBe($directory . DIRECTORY_SEPARATOR . 'capell-error-pages.json')
            ->and(File::exists(storage_path('framework/capell-error-pages-fallback.json')))->toBeFalse()
            ->and(ErrorPageFallbackManifest::forHost('any.test', '500')['logo_url'])->toBe('https://cdn.test/Relocated.svg');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('preserves per-status explicit fallback without merging partial branded copy', function (): void {
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

it('resolves published host-only fallback calls from the current request without database queries', function (string $url, string $scheme, int $port, string $mount): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);
    $store->replaceSite(1, [[
        'scheme' => 'https', 'port' => null, 'domain' => 'shared.test', 'path' => '/',
        ...fallbackBranding('ROOT'),
    ]]);
    $store->replaceSite(2, [[
        'scheme' => $scheme, 'port' => $port, 'domain' => 'shared.test', 'path' => $mount,
        ...fallbackBranding('REQUEST'),
    ]]);
    $originalRequest = resolve('request');
    app()->instance('request', Request::create($url));
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    try {
        // App minimal.blade.php uses forHost(host, status); layout.blade.php
        // uses logoUrl(host). Other installed views still use copy(host, status).
        expect(ErrorPageFallbackManifest::forHost('SHARED.test', '500')['copy']['headline'] ?? null)->toBe('REQUEST headline')
            ->and(ErrorPageFallbackManifest::forHost('shared.test', '500', $scheme)['copy']['headline'] ?? null)->toBe('REQUEST headline')
            ->and(ErrorPageFallbackManifest::logoUrl('shared.test'))->toBe('https://cdn.test/REQUEST.svg')
            ->and(ErrorPageFallbackManifest::copy('shared.test', '500')['headline'] ?? null)->toBe('REQUEST headline')
            ->and(ErrorPageFallbackManifest::forHost('unrelated.test', '500'))->toBe(['logo_url' => null, 'copy' => null])
            ->and(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', null, '/')['copy']['headline'] ?? null)->toBe('ROOT headline')
            ->and($queries)->toBe(0);
    } finally {
        app()->instance('request', $originalRequest);
    }
})->with([
    'port and mount' => ['https://shared.test:8443/docs/missing', 'https', 8443, '/docs'],
    'mount' => ['https://shared.test/docs/missing', 'https', 443, '/docs'],
    'scheme' => ['http://shared.test/docs/missing', 'http', 80, '/docs'],
]);

it('ignores legacy host and default writes that preserve the converted manifest version', function (): void {
    $store = resolve(ErrorPageFallbackManifestStore::class);
    $store->replaceSite(2, [[
        'scheme' => 'https', 'port' => 8443, 'domain' => 'shared.test', 'path' => '/docs',
        ...fallbackBranding('ROUTE'),
    ]]);
    $oldWriter = new LegacyErrorPageFallbackManifestStore;
    $wrong = fallbackBranding('WRONG OWNER');
    // These are the original setHost/setDefault implementations: each reads
    // the existing v2 file, changes only its own field, and writes it back.
    $oldWriter->setHost('shared.test', $wrong['logo_url'], $wrong['copy']);
    $oldWriter->setDefault($wrong['logo_url'], $wrong['copy']);

    expect($store->read()['version'])->toBe(2)
        ->and($store->read()['hosts']['shared.test'])->toBe($wrong)
        ->and($store->read()['default'])->toBe($wrong)
        ->and(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 8443, '/docs/missing')['copy']['headline'] ?? null)->toBe('ROUTE headline')
        ->and(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 443, '/docs'))->toBe(['logo_url' => null, 'copy' => null])
        ->and(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 8443, '/outside'))->toBe(['logo_url' => null, 'copy' => null])
        ->and(ErrorPageFallbackManifest::forHost('unrelated.test', '500'))->toBe(['logo_url' => null, 'copy' => null]);

    $explicit = fallbackBranding('EXPLICIT');
    $store->setHost('shared.test', $explicit['logo_url'], $explicit['copy']);
    $store->setDefault($explicit['logo_url'], $explicit['copy']);

    $oldWriter->setHost('shared.test', $wrong['logo_url'], $wrong['copy']);
    $oldWriter->setDefault($wrong['logo_url'], $wrong['copy']);
    expect(ErrorPageFallbackManifest::logoUrl('shared.test'))->toBe($explicit['logo_url'])
        ->and(ErrorPageFallbackManifest::copy('unrelated.test', '500'))->toBe($explicit['copy'][500]);
});

it('fails closed when an old write read before conversion lands after conversion', function (): void {
    $oldWriter = new LegacyErrorPageFallbackManifestStore;
    $inFlight = $oldWriter->read();
    resolve(ErrorPageFallbackManifestStore::class)->replaceSite(2, [[
        'scheme' => 'https', 'port' => 8443, 'domain' => 'shared.test', 'path' => '/docs',
        ...fallbackBranding('ROUTE'),
    ]]);
    // Pause the original setHost sequence after read(), deploy, then resume
    // its field assignment and write(). It can lose new routes, never expose branding.
    $inFlight['hosts']['shared.test'] = fallbackBranding('WRONG OWNER');
    $oldWriter->write($inFlight);
    $wrong = fallbackBranding('WRONG DEFAULT');
    $oldWriter->setDefault($wrong['logo_url'], $wrong['copy']);

    expect(ErrorPageFallbackManifest::forHost('shared.test', '500', 'https', 8443, '/docs'))->toBe(['logo_url' => null, 'copy' => null])
        ->and(ErrorPageFallbackManifest::forHost('shared.test', '500'))->toBe(['logo_url' => null, 'copy' => null])
        ->and(ErrorPageFallbackManifest::forHost('unrelated.test', '500'))->toBe(['logo_url' => null, 'copy' => null]);
});

it('does not infer explicit override provenance from old version markers', function (int $version): void {
    resolve(ErrorPageFallbackManifestStore::class)->write([
        'version' => $version, 'hosts' => ['shared.test' => fallbackBranding('WRONG HOST')],
        'default' => fallbackBranding('WRONG DEFAULT'),
    ]);

    expect(ErrorPageFallbackManifest::forHost('shared.test', '500'))->toBe(['logo_url' => null, 'copy' => null])
        ->and(ErrorPageFallbackManifest::forHost('unrelated.test', '500'))->toBe(['logo_url' => null, 'copy' => null]);
})->with([1, 2]);
