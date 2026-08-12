<?php

declare(strict_types=1);

use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Contracts\AdminAccessCheckerInterface;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Contracts\FrontendRuntimeManifestContributor;
use Capell\Frontend\Data\FrontendRuntimeManifestData;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

it('keeps critical-eligible theme css blocking on public routes without an optimizer', function (): void {
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    config()->set('capell-frontend.stylesheet_recovery', [
        'enabled' => true,
        'fallback_url' => '/vendor/capell-frontend/capell-frontend.css',
        'runtime_url' => '/vendor/capell-frontend/stylesheet-recovery.js',
    ]);
    Cache::flush();

    $theme = Theme::factory()->createOne([
        'meta' => ['assets' => ['vendor/theme/theme.css']],
    ]);
    $site = Site::factory()
        ->theme($theme)
        ->withTranslations(siteDomainData: [
            'domain' => 'localhost',
            'scheme' => 'http',
            'path' => null,
            'default' => true,
        ])
        ->create();

    Page::factory()
        ->site($site)
        ->home()
        ->withTranslations(data: ['title' => 'Theme fallback'], slug: '/')
        ->create(['meta' => null]);

    $response = $this->followingRedirects()->get('/', ['HTTP_HOST' => 'localhost']);

    $response
        ->assertOk()
        ->assertSee('href="http://localhost/vendor/theme/theme.css"', false)
        ->assertSee('data-capell-stylesheet-recovery', false)
        ->assertDontSee('data-deferred-stylesheet', false)
        ->assertDontSee('data-capell-authoring', false);
});

it('renders blade-only public pages without livewire or frontend runtime scripts', function (): void {
    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    Cache::flush();

    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost',
        'scheme' => 'http',
        'path' => null,
        'default' => true,
    ])->create();

    Page::factory()
        ->site($site)
        ->home()
        ->withTranslations(data: ['title' => 'Static homepage'], slug: '/')
        ->create(['meta' => null]);

    $domain = $site->siteDomains->first();
    $server = ['HTTP_HOST' => $domain->domain];

    if (($domain->scheme ?? 'https') === 'https') {
        $server['HTTPS'] = 'on';
    }

    $response = $this->followingRedirects()->get($domain->path ?? '/', $server);

    $response
        ->assertOk()
        ->assertSee('Static homepage')
        ->assertDontSee('wire:navigate', false)
        ->assertDontSee('x-data=', false)
        ->assertDontSee('Alpine.data', false)
        ->assertDontSee('window.beaconData', false)
        ->assertDontSee('frontend-resource-debug-overlay', false)
        ->assertDontSee('/livewire/', false)
        ->assertDontSee('@livewireScripts', false);
});

it('does not expose the beacon runtime just because a beacon route exists', function (): void {
    Route::post('/capell-test-beacon', fn () => response()->json(['scripts' => []]))
        ->name('capell-frontend.beacon');

    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    Cache::flush();

    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost',
        'scheme' => 'http',
        'path' => null,
        'default' => true,
    ])->create();

    Page::factory()
        ->site($site)
        ->home()
        ->withTranslations(data: ['title' => 'Static homepage'], slug: '/')
        ->create(['meta' => null]);

    $domain = $site->siteDomains->first();
    $server = ['HTTP_HOST' => $domain->domain];

    if (($domain->scheme ?? 'https') === 'https') {
        $server['HTTPS'] = 'on';
    }

    $response = $this->followingRedirects()->get($domain->path ?? '/', $server);

    $response
        ->assertOk()
        ->assertSee('Static homepage')
        ->assertDontSee('window.beaconData', false)
        ->assertDontSee('/capell-test-beacon', false);
});

it('does not expose the beacon runtime to authenticated admins when a contributor requests it', function (): void {
    Route::post('/capell-test-beacon', fn () => response()->json(['scripts' => []]))
        ->name('capell-frontend.beacon');

    app()->singleton('test.force-beacon-runtime-manifest-contributor', fn (): FrontendRuntimeManifestContributor => new class implements FrontendRuntimeManifestContributor
    {
        public function contribute(FrontendContextReader $context, FrontendRuntimeManifestData $manifest): void
        {
            $manifest->usesBeacon = true;
        }
    });
    app()->tag(['test.force-beacon-runtime-manifest-contributor'], FrontendRuntimeManifestContributor::TAG);
    app()->instance(AdminAccessCheckerInterface::class, new class implements AdminAccessCheckerInterface
    {
        public function isAdmin(Authenticatable $user): bool
        {
            return true;
        }
    });

    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    Cache::flush();

    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost',
        'scheme' => 'http',
        'path' => null,
        'default' => true,
    ])->create();

    Page::factory()
        ->site($site)
        ->home()
        ->withTranslations(data: ['title' => 'Static homepage'], slug: '/')
        ->create(['meta' => null]);

    $domain = $site->siteDomains->first();
    $server = ['HTTP_HOST' => $domain->domain];

    if (($domain->scheme ?? 'https') === 'https') {
        $server['HTTPS'] = 'on';
    }

    test()->actingAs(User::factory()->createOne());

    $response = $this->followingRedirects()->get($domain->path ?? '/', $server);

    $response
        ->assertOk()
        ->assertSee('Static homepage')
        ->assertDontSee('window.beaconData', false)
        ->assertDontSee('/capell-test-beacon', false);
});

it('does not expose the beacon runtime to anonymous visitors when a contributor requests it', function (): void {
    Route::post('/capell-test-beacon', fn () => response()->json(['scripts' => []]))
        ->name('capell-frontend.beacon');

    app()->singleton('test.force-beacon-runtime-manifest-contributor', fn (): FrontendRuntimeManifestContributor => new class implements FrontendRuntimeManifestContributor
    {
        public function contribute(FrontendContextReader $context, FrontendRuntimeManifestData $manifest): void
        {
            $manifest->usesBeacon = true;
        }
    });
    app()->tag(['test.force-beacon-runtime-manifest-contributor'], FrontendRuntimeManifestContributor::TAG);

    config()->set('capell-frontend.html_cache', false);
    config()->set('capell-frontend.write_html_cache', false);
    Cache::flush();

    $site = Site::factory()->withTranslations(siteDomainData: [
        'domain' => 'localhost',
        'scheme' => 'http',
        'path' => null,
        'default' => true,
    ])->create();

    Page::factory()
        ->site($site)
        ->home()
        ->withTranslations(data: ['title' => 'Static homepage'], slug: '/')
        ->create(['meta' => null]);

    $domain = $site->siteDomains->first();
    $server = ['HTTP_HOST' => $domain->domain];

    if (($domain->scheme ?? 'https') === 'https') {
        $server['HTTPS'] = 'on';
    }

    $response = $this->followingRedirects()->get($domain->path ?? '/', $server);

    $response
        ->assertOk()
        ->assertSee('Static homepage')
        ->assertDontSee('window.beaconData', false)
        ->assertDontSee('/capell-test-beacon', false);
});

it('renders page data with a same origin beacon path', function (): void {
    Route::post('/capell-test-beacon', fn () => response()->json(['scripts' => []]))
        ->name('capell-test.beacon');
    Route::getRoutes()->refreshNameLookups();

    config()->set('capell-page.frontend.route_name', 'capell-test.beacon');

    $rendered = view('capell::components.page-data')->render();

    expect($rendered)
        ->toContain('"url":"\/capell-test-beacon"')
        ->toContain('new URL(beacon.url, window.location.origin)')
        ->toContain('requestIdleCallback')
        ->not->toContain('http:\/\/localhost\/capell-test-beacon');
});
