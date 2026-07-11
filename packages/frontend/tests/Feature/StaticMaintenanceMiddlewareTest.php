<?php

declare(strict_types=1);

use Capell\Core\Support\Security\LockdownStore;
use Capell\Frontend\Contracts\StaticMaintenancePageStore;
use Capell\Frontend\Http\Middleware\ServeStaticMaintenancePage;
use Capell\Frontend\Settings\FrontendSettings;
use Capell\Frontend\Support\Maintenance\MaintenanceManifestStore;
use Capell\Frontend\Support\Routing\FrontendRouteMiddlewareRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    File::delete(resolve(MaintenanceManifestStore::class)->path());
    config()->set('capell.lockdown.file', storage_path('framework/testing/frontend-lockdown.json'));
    File::delete(config('capell.lockdown.file'));

    app()->singleton(StaticMaintenancePageStore::class, fn (): object => new class implements StaticMaintenancePageStore
    {
        /** @var array<string, string> */
        public array $files = [
            'maintenance/https.example.test/index.html' => '<h1>Maintenance</h1>',
        ];

        public function exists(string $file): bool
        {
            return isset($this->files[$file]);
        }

        public function path(string $file): ?string
        {
            if (! $this->exists($file)) {
                return null;
            }

            $path = storage_path('framework/testing/' . str_replace('/', '-', $file));
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $this->files[$file]);

            return $path;
        }

        public function put(string $file, string $contents): void
        {
            $this->files[$file] = $contents;
        }
    });
});

afterEach(function (): void {
    File::delete(resolve(MaintenanceManifestStore::class)->path());
    File::delete(config('capell.lockdown.file'));
});

it('serves host specific static maintenance html without querying content tables', function (): void {
    resolve(MaintenanceManifestStore::class)->write([
        'sites' => [
            '10' => [
                'active' => true,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/index.html',
                ]],
            ],
        ],
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://example.test/products'),
        fn (): Response => response('fresh html'),
    );

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toBe('<h1>Maintenance</h1>')
        ->and($queries)->toBeLessThanOrEqual(1);
});

it('is registered before frontend resolution in the frontend route middleware stack', function (): void {
    $middleware = resolve(FrontendRouteMiddlewareRegistry::class)->all();

    expect(array_search('frontend.maintenance', $middleware, true))
        ->toBeGreaterThan(array_search('web', $middleware, true))
        ->toBeLessThan(array_search('frontend.resolve', $middleware, true));
});

it('serves path mounted site maintenance html for the exact mounted path', function (): void {
    $store = resolve(StaticMaintenancePageStore::class);
    $store->put('maintenance/https.example.test/docs/index.html', '<h1>Docs maintenance</h1>');

    resolve(MaintenanceManifestStore::class)->write([
        'sites' => [
            '10' => [
                'active' => true,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/docs',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/docs/index.html',
                ]],
            ],
        ],
    ]);

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://example.test/docs'),
        fn (): Response => response('fresh html'),
    );

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toBe('<h1>Docs maintenance</h1>');
});

it('uses Laravel maintenance response headers for static maintenance html', function (): void {
    resolve(MaintenanceManifestStore::class)->write([
        'global_active' => true,
        'sites' => [
            '10' => [
                'active' => false,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/index.html',
                ]],
            ],
        ],
    ]);

    app()->maintenanceMode()->activate([
        'retry' => 60,
        'refresh' => 15,
        'status' => 503,
    ]);

    try {
        $response = resolve(ServeStaticMaintenancePage::class)->handle(
            Request::create('https://example.test/products'),
            fn (): Response => response('fresh html'),
        );
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($response->getStatusCode())->toBe(503)
        ->and($response->headers->get('Retry-After'))->toBe('60')
        ->and($response->headers->get('Refresh'))->toBe('15')
        ->and($response->headers->get('Content-Type'))->toBe('text/html; charset=UTF-8');
});

it('allows requests through when no global or site maintenance mode is active', function (): void {
    resolve(MaintenanceManifestStore::class)->write([
        'sites' => [
            '10' => [
                'active' => false,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/index.html',
                ]],
            ],
        ],
    ]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://example.test/products'),
        fn (): Response => response('fresh html'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('fresh html')
        ->and($queries)->toBe(0);
});

it('serves static maintenance html when lockdown is active', function (): void {
    resolve(MaintenanceManifestStore::class)->write([
        'sites' => [
            '10' => [
                'active' => false,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/index.html',
                ]],
            ],
        ],
    ]);

    resolve(LockdownStore::class)->activateFor(lockdownTestUser());

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://example.test/products'),
        fn (): Response => response('fresh html'),
    );

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toBe('<h1>Maintenance</h1>');
});

it('falls back to a plain maintenance response during lockdown when no static page matches', function (): void {
    resolve(LockdownStore::class)->activateFor(lockdownTestUser());

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://missing.test/products'),
        fn (): Response => response('fresh html'),
    );

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toContain('This site is temporarily unavailable.');
});

it('does not honor Laravel maintenance bypass secrets during lockdown', function (): void {
    resolve(LockdownStore::class)->activateFor(lockdownTestUser());
    app()->maintenanceMode()->activate([
        'secret' => 'let-me-in',
        'template' => '<h1>Laravel maintenance</h1>',
        'status' => 503,
    ]);

    try {
        $response = resolve(ServeStaticMaintenancePage::class)->handle(
            Request::create('https://example.test/let-me-in'),
            fn (): Response => response('fresh html'),
        );
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($response->getStatusCode())->toBe(503)
        ->and(collect($response->headers->getCookies())->map(fn (Cookie $cookie): string => $cookie->getName())->all())
        ->not->toContain('laravel_maintenance');
});

it('does not honor existing Laravel maintenance bypass cookies during lockdown', function (): void {
    resolve(MaintenanceManifestStore::class)->write([
        'sites' => [
            '10' => [
                'active' => false,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/index.html',
                ]],
            ],
        ],
    ]);

    app()->maintenanceMode()->activate([
        'secret' => 'let-me-in',
        'template' => '<h1>Laravel maintenance</h1>',
        'status' => 503,
    ]);

    try {
        $bypassResponse = resolve(ServeStaticMaintenancePage::class)->handle(
            Request::create('https://example.test/let-me-in'),
            fn (): Response => response('fresh html'),
        );

        resolve(LockdownStore::class)->activateFor(lockdownTestUser());

        $request = Request::create('https://example.test/products');

        foreach ($bypassResponse->headers->getCookies() as $cookie) {
            $request->cookies->set($cookie->getName(), $cookie->getValue());
        }

        $called = false;
        $response = resolve(ServeStaticMaintenancePage::class)->handle(
            $request,
            function () use (&$called): Response {
                $called = true;

                return response('fresh html');
            },
        );
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toBe('<h1>Maintenance</h1>')
        ->and($called)->toBeFalse();
});

it('bypasses Capell static maintenance pages when custom maintenance pages are disabled', function (): void {
    $settings = resolve(FrontendSettings::class);
    $settings->custom_maintenance_page_enabled = false;
    $settings->save();

    resolve(MaintenanceManifestStore::class)->write([
        'sites' => [
            '10' => [
                'active' => true,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/index.html',
                ]],
            ],
        ],
    ]);

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://example.test/products'),
        fn (): Response => response('fresh html'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('fresh html');
});

it('preserves Laravel rendered maintenance template fallback when static html is missing', function (): void {
    app()->maintenanceMode()->activate([
        'template' => '<h1>Laravel maintenance</h1>',
        'status' => 503,
    ]);

    try {
        $response = resolve(ServeStaticMaintenancePage::class)->handle(
            Request::create('https://missing.test/products'),
            fn (): Response => response('fresh html'),
        );
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toBe('<h1>Laravel maintenance</h1>');
});

it('redirects to the Laravel maintenance redirect target before serving static html', function (): void {
    resolve(MaintenanceManifestStore::class)->write([
        'global_active' => true,
        'sites' => [
            '10' => [
                'active' => true,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/index.html',
                ]],
            ],
        ],
    ]);

    app()->maintenanceMode()->activate([
        'redirect' => '/maintenance',
        'status' => 503,
    ]);

    try {
        $response = resolve(ServeStaticMaintenancePage::class)->handle(
            Request::create('https://example.test/products'),
            fn (): Response => response('fresh html'),
        );
    } finally {
        app()->maintenanceMode()->deactivate();
    }

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toBe('http://localhost/maintenance');
});

it('throws Laravel maintenance fallback when there is no template or static html', function (): void {
    app()->maintenanceMode()->activate([
        'status' => 503,
    ]);

    try {
        resolve(ServeStaticMaintenancePage::class)->handle(
            Request::create('https://missing.test/products'),
            fn (): Response => response('fresh html'),
        );
    } finally {
        app()->maintenanceMode()->deactivate();
    }
})->throws(HttpException::class, 'Service Unavailable');

it('ignores malformed manifest entries and uses the longest matching mounted domain', function (): void {
    $store = resolve(StaticMaintenancePageStore::class);
    $store->put('maintenance/https.example.test/docs/index.html', '<h1>Docs mounted maintenance</h1>');

    resolve(MaintenanceManifestStore::class)->write([
        'global_active' => true,
        'sites' => [
            'invalid',
            '10' => [
                'active' => false,
                'domains' => [
                    'invalid-domain-entry',
                    [
                        'scheme' => 'http',
                        'domain' => 'example.test',
                        'path' => '/',
                        'site_id' => 10,
                        'file' => 'maintenance/https.example.test/index.html',
                    ],
                    [
                        'scheme' => 'https',
                        'domain' => 'example.test',
                        'path' => '/',
                        'site_id' => 10,
                        'file' => 'maintenance/https.example.test/index.html',
                    ],
                    [
                        'scheme' => 'https',
                        'domain' => 'example.test',
                        'path' => '/docs',
                        'site_id' => 10,
                        'file' => 'maintenance/https.example.test/docs/index.html',
                    ],
                ],
            ],
        ],
    ]);

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://example.test/docs/guide'),
        fn (): Response => response('fresh html'),
    );

    expect($response->getStatusCode())->toBe(503)
        ->and($response->getContent())->toBe('<h1>Docs mounted maintenance</h1>');
});

it('falls through when matching static maintenance storage is unavailable or incomplete', function (): void {
    app()->forgetInstance(StaticMaintenancePageStore::class);
    app()->offsetUnset(StaticMaintenancePageStore::class);

    resolve(MaintenanceManifestStore::class)->write([
        'global_active' => true,
        'sites' => [
            '10' => [
                'active' => false,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/index.html',
                ]],
            ],
        ],
    ]);

    $response = resolve(ServeStaticMaintenancePage::class)->handle(
        Request::create('https://example.test/products'),
        fn (): Response => response('fresh html'),
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toBe('fresh html');
});

it('removes stale matching domain entries from other sites when replacing manifest domains', function (): void {
    $manifestStore = resolve(MaintenanceManifestStore::class);

    $manifestStore->write([
        'sites' => [
            '10' => [
                'active' => true,
                'domains' => [[
                    'scheme' => 'https',
                    'domain' => 'example.test',
                    'path' => '/',
                    'site_id' => 10,
                    'file' => 'maintenance/https.example.test/old/index.html',
                ]],
            ],
        ],
    ]);

    $manifestStore->replaceSiteDomains(20, [[
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/',
        'site_id' => 20,
        'file' => 'maintenance/https.example.test/index.html',
    ]]);

    expect(data_get($manifestStore->read(), 'sites.10.domains'))->toBe([])
        ->and(data_get($manifestStore->read(), 'sites.20.domains.0.site_id'))->toBe(20);
});

function lockdownTestUser(): Authenticatable
{
    return new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}
