<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Contracts\Extenders\DeclaresFullPageOnlyMiddleware;
use Capell\Admin\Enums\SidebarCollapseEnum;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LatePanelRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateRuntimePage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateRuntimeResource;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateRuntimeWidget;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateSecurityMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateSecurityPanelExtender;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeAllowMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeBlockMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeCounterComponent;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeFullPageOnlyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeTenantMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeWireBlockMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestAdminPanelExtender;
use Capell\Core\Actions\DisablePackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Marketplace\Actions\PropagateMarketplaceRuntimeStateAction;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Filament\Facades\Filament;
use Filament\Http\Middleware\IdentifyTenant;
use Filament\Http\Middleware\SetUpPanel;
use Filament\Panel;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

use function Pest\Laravel\get;

beforeEach(function (): void {
    TestAdminPanelExtender::$called = false;
});

it('runs tagged admin panel extenders while registering the admin plugin', function (): void {
    app()->tag([TestAdminPanelExtender::class], AdminPanelExtender::TAG);

    CapellAdminPlugin::make()->register(Panel::make());

    expect(TestAdminPanelExtender::$called)->toBeTrue();
});

it('retains duplicate extender registrations while applying each tag entry once', function (): void {
    $extender = new class implements AdminPanelExtender
    {
        public int $calls = 0;

        #[Override]
        public function extend(Panel $panel): void
        {
            $this->calls++;
        }
    };
    app()->instance('runtime.counted-extender', $extender);
    app()->tag(['runtime.counted-extender', 'runtime.counted-extender'], AdminPanelExtender::TAG);

    $panel = Panel::make();
    resolve(InstalledPanelRuntime::class)->extend($panel);
    resolve(InstalledPanelRuntime::class)->extend($panel);

    expect($extender->calls)->toBe(2);
});

it('refuses ambiguous late authentication middleware on existing panel routes', function (): void {
    $panel = Panel::make()->id('runtime-empty');
    Route::get('runtime-empty/probe', static fn (): string => 'probe')->name('filament.runtime-empty.probe');
    app()->tag([LateSecurityPanelExtender::class], AdminPanelExtender::TAG);

    expect(fn () => resolve(InstalledPanelRuntime::class)->extend($panel))
        ->toThrow(RuntimeException::class, 'authenticated panel routes');
});

it('denies requests and refuses retry after an extender partially fails', function (): void {
    $panel = Panel::make()->id('runtime-retry')->authMiddleware([RuntimeAllowMiddleware::class]);
    Route::get('runtime-retry/probe', static fn (): string => 'probe')
        ->middleware([RuntimeAllowMiddleware::class])->name('filament.runtime-retry.probe');
    $extender = new class implements AdminPanelExtender
    {
        public bool $fail = true;

        #[Override]
        public function extend(Panel $panel): void
        {
            $panel->authMiddleware([LateSecurityMiddleware::class]);
            throw_if($this->fail, RuntimeException::class, 'Extender failed after changing the panel.');
        }
    };
    app()->instance('runtime.retry-extender', $extender);
    app()->tag(['runtime.retry-extender'], AdminPanelExtender::TAG);

    expect(fn () => resolve(InstalledPanelRuntime::class)->extend($panel))->toThrow(RuntimeException::class);
    $extender->fail = false;
    $this->get('/runtime-retry/probe')->assertStatus(503);
    expect(fn () => resolve(InstalledPanelRuntime::class)->extend($panel))->toThrow(RuntimeException::class, 'fresh application');
    expect(array_count_values($panel->getAuthMiddleware())[LateSecurityMiddleware::class])->toBe(1);
});

it('registers the admin tools dropdown in the topbar render hooks', function (): void {
    $panel = Panel::make();

    CapellAdminPlugin::make()->register($panel);

    $reflection = new ReflectionClass($panel);
    $renderHooks = $reflection->getProperty('renderHooks')->getValue($panel);

    expect($renderHooks)
        ->toHaveKey(PanelsRenderHook::GLOBAL_SEARCH_AFTER)
        ->and($renderHooks[PanelsRenderHook::GLOBAL_SEARCH_AFTER][''])
        ->toHaveCount(1);
});

it('starts a hidden-until-opened sidebar without the collapsed navigation rail', function (): void {
    $settings = resolve(AdminSettings::class);
    $settings->sidebar_collapsible = SidebarCollapseEnum::HiddenUntilOpened;
    $settings->save();

    app()->forgetInstance(AdminSettings::class);

    $panel = Panel::make();

    CapellAdminPlugin::make()->register($panel);

    expect($panel->isSidebarCollapsibleOnDesktop())->toBeFalse()
        ->and($panel->isSidebarFullyCollapsibleOnDesktop())->toBeTrue();

    $reflection = new ReflectionClass($panel);
    $renderHooks = $reflection->getProperty('renderHooks')->getValue($panel);
    $hooks = $renderHooks[PanelsRenderHook::HEAD_START][''];

    expect($hooks)->toHaveCount(1);

    $view = $hooks[0]();

    expect($view)->toBeInstanceOf(View::class)
        ->and($view->render())->toContain("window.localStorage.setItem('isOpen', 'false')")
        ->toContain("window.localStorage.setItem('isOpenDesktop', 'false')")
        ->toContain('.fi-topbar-open-sidebar-btn');
});

it('registers the shared Tailwind layer order as a request-loaded Filament asset', function (): void {
    $styles = FilamentAsset::getStyles([AdminServiceProvider::$packageName]);

    expect($styles)->toHaveCount(1)
        ->and($styles[0])->toBeInstanceOf(Css::class)
        ->and($styles[0]->getId())->toBe(AdminServiceProvider::CSS_LAYER_ORDER_ASSET_ID)
        ->and($styles[0]->isLoadedOnRequest())->toBeTrue()
        ->and($styles[0]->getPath())->toBeFile();
});

it('keeps the shared Tailwind layer declaration in cascade order', function (): void {
    $styles = FilamentAsset::getStyles([AdminServiceProvider::$packageName]);
    $path = $styles[0]->getPath();

    expect($path)->toBeString();
    assert(is_string($path));

    $contents = file_get_contents($path);

    // The first layer declaration controls precedence across separately
    // loaded stylesheets; assert the layer names and their order without
    // coupling the test to CSS formatting.
    expect($contents)->toBeString();
    assert(is_string($contents));

    $positions = [];

    foreach (['properties', 'theme', 'base', 'components', 'utilities'] as $layer) {
        $position = strpos($contents, $layer);

        expect($position)->toBeInt();
        assert(is_int($position));
        $positions[] = $position;
    }

    expect($positions[0])->toBeLessThan($positions[1])
        ->and($positions[1])->toBeLessThan($positions[2])
        ->and($positions[2])->toBeLessThan($positions[3])
        ->and($positions[3])->toBeLessThan($positions[4]);
});

it('loads the shared Tailwind layer order through the Filament styles hook', function (): void {
    $panel = Panel::make();

    CapellAdminPlugin::make()->register($panel);

    $reflection = new ReflectionClass($panel);
    $renderHooks = $reflection->getProperty('renderHooks')->getValue($panel);
    $hooks = $renderHooks[PanelsRenderHook::STYLES_BEFORE][''];

    expect($hooks)->toHaveCount(1)
        ->and((string) $hooks[0]())
        ->toContain('rel="stylesheet"')
        ->toContain('/css/capell-app/admin/admin-layer-order.css');
});

it('keeps the layer prelude on the uninstalled admin path', function (): void {
    CapellCore::forcePackageInstalled(AdminServiceProvider::$packageName, false);

    $panel = Panel::make();

    CapellAdminPlugin::make()->register($panel);

    $reflection = new ReflectionClass($panel);
    $renderHooks = $reflection->getProperty('renderHooks')->getValue($panel);
    $hooks = $renderHooks[PanelsRenderHook::STYLES_BEFORE][''];
    $renderedHooks = implode('', array_map(static fn (callable $hook): string => (string) $hook(), $hooks));

    expect($renderedHooks)
        ->toContain('rel="stylesheet"')
        ->toContain('/css/capell-app/admin/admin-layer-order.css');
});

it('emits the layer-order stylesheet before a registered extension stylesheet', function (): void {
    FilamentAsset::register([
        Css::make('layered-extension-fixture', 'https://example.test/layered-extension.css'),
    ], 'layered-extension-fixture');

    $html = get('/admin/login')->assertOk()->getContent();
    $preludePosition = strpos($html, 'admin-layer-order.css');
    $extensionPosition = strpos($html, 'https://example.test/layered-extension.css');

    expect(substr_count($html, 'admin-layer-order.css'))->toBe(1);
    expect($preludePosition)->toBeInt();
    expect($extensionPosition)->toBeInt();
    assert(is_int($preludePosition));
    assert(is_int($extensionPosition));

    expect($preludePosition)->toBeLessThan($extensionPosition);
});

it('applies a late security extender to an existing panel and its existing authenticated routes', function (bool $compiled): void {
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $this->actingAsAdmin();
    // The route has already copied the panel middleware before installation.
    Route::get('/admin/runtime-security-probe', fn (): string => 'protected')
        ->middleware($panel->getMiddleware())
        ->middleware($panel->getAuthMiddleware())
        ->name('filament.admin.runtime-security-probe');
    if ($compiled) {
        $routes = resolve(Router::class)->getRoutes();
        throw_unless($routes instanceof RouteCollection, RuntimeException::class, 'Expected uncached fixture routes.');

        resolve(Router::class)->setCompiledRoutes($routes->compile());
    }

    $this->get('/admin/runtime-security-probe')->assertOk();
    CapellCore::registerPackage('test/panel-runtime');
    CapellCore::forcePackageInstalled('test/panel-runtime', false);
    app()->register(LatePanelRuntimeProvider::class);
    InstallPackageAction::run(CapellCore::getPackage('test/panel-runtime'));
    $this->get('/admin/runtime-security-probe')->assertRedirect('/admin/change-password');
    CapellAdminPlugin::make()->synchronizeCurrentPanelAdminSurface();
    CapellAdminPlugin::make()->synchronizeCurrentPanelAdminSurface();
    $this->get('/admin/runtime-security-probe')->assertRedirect('/admin/change-password');
    expect(array_count_values($panel->getAuthMiddleware())[LateSecurityMiddleware::class] ?? 0)->toBe(1);
})->with([false, true]);

it('defers new pages resources and widgets until a fresh application constructs routes', function (): void {
    $panel = Filament::getPanel('admin');
    CapellCore::registerPackage('test/panel-runtime');
    CapellCore::forcePackageInstalled('test/panel-runtime', false);
    app()->register(LatePanelRuntimeProvider::class);
    InstallPackageAction::run(CapellCore::getPackage('test/panel-runtime'));
    CapellAdminPlugin::make()->synchronizePanelAdminSurface($panel);
    expect($panel->getPages())->not->toContain(LateRuntimePage::class)
        ->and($panel->getResources())->not->toContain(LateRuntimeResource::class)
        ->and($panel->getWidgets())->not->toContain(LateRuntimeWidget::class);
    $this->get('/admin/late-runtime')->assertNotFound();
});

it('closes existing routes after ambiguous security refresh', function (): void {
    $panel = Panel::make()->id('runtime-ambiguous');
    Route::get('/runtime-ambiguous/probe', static fn (): string => 'open')->name('filament.runtime-ambiguous.probe');
    app()->tag([LateSecurityPanelExtender::class], AdminPanelExtender::TAG);
    expect(fn () => resolve(InstalledPanelRuntime::class)->extend($panel))->toThrow(RuntimeException::class);
    $this->get('/runtime-ambiguous/probe')->assertStatus(503);
});

it('protects grouped unnamed and tenant routes during late security refresh', function (string $shape): void {
    $panel = Panel::make()->id('coverage')->authMiddleware([RuntimeAllowMiddleware::class]);
    Filament::registerPanel($panel);
    resolve(Router::class)->middlewareGroup('runtime-auth', [RuntimeAllowMiddleware::class]);
    resolve(Router::class)->middlewareGroup('runtime-panel', ['panel:coverage', RuntimeAllowMiddleware::class]);

    $middleware = match ($shape) {
        'group' => ['runtime-auth'],
        'unnamed' => ['panel:coverage', RuntimeAllowMiddleware::class],
        'unnamed-excluded-setup' => ['runtime-panel'],
        'tenant' => [RuntimeAllowMiddleware::class, IdentifyTenant::class],
        default => throw new LogicException('Unknown route fixture.'),
    };
    $route = Route::get('/runtime-coverage', static fn (): string => 'open')->middleware($middleware);
    if ($shape === 'unnamed-excluded-setup') {
        $route->withoutMiddleware(SetUpPanel::class . ':coverage');
    }

    if (! str_starts_with($shape, 'unnamed')) {
        $route->name('filament.coverage.probe');
    }

    $extender = new class implements AdminPanelExtender
    {
        #[Override]
        public function extend(Panel $panel): void
        {
            $panel->authMiddleware([RuntimeBlockMiddleware::class]);
            $panel->tenantMiddleware([RuntimeTenantMiddleware::class]);
        }
    };
    app()->instance('runtime.coverage', $extender);
    app()->tag(['runtime.coverage'], AdminPanelExtender::TAG);

    resolve(InstalledPanelRuntime::class)->extend($panel);
    expect($route->middleware())->toContain(RuntimeBlockMiddleware::class);
    if ($shape === 'tenant') {
        expect($route->middleware())->toContain(RuntimeTenantMiddleware::class);
    }

    $this->get('/runtime-coverage')->assertForbidden();
})->with(['group', 'unnamed', 'unnamed-excluded-setup', 'tenant']);

it('enforces default extender middleware on an existing Livewire update snapshot', function (): void {
    $panel = Panel::make()->id('runtime-wire')->authMiddleware([RuntimeAllowMiddleware::class], isPersistent: true);
    Livewire::component('runtime.counter', RuntimeCounterComponent::class);
    Route::get('/runtime-wire', static fn (): string => Livewire::mount('runtime.counter'))
        ->middleware([RuntimeAllowMiddleware::class])->name('filament.runtime-wire.counter');
    $html = $this->get('/runtime-wire')->assertOk()->getContent();
    throw_unless(is_string($html), RuntimeException::class, 'Expected component HTML.');
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = html_entity_decode($matches[1] ?? throw new RuntimeException('Missing Livewire snapshot.'), ENT_QUOTES);
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'increment', 'params' => []]]]]];
    $uri = resolve(HandleRequests::class)->getUpdateUri();
    $this->postJson($uri, $payload, ['X-Livewire' => 'true'])->assertOk();
    $extender = new class implements AdminPanelExtender
    {
        #[Override]
        public function extend(Panel $panel): void
        {
            $panel->authMiddleware([RuntimeWireBlockMiddleware::class]);
        }
    };
    app()->instance('runtime.wire', $extender);
    app()->tag(['runtime.wire'], AdminPanelExtender::TAG);

    resolve(InstalledPanelRuntime::class)->extend($panel);
    $panel->register();
    $this->get('/runtime-wire')->assertForbidden();
    Livewire::flushState();
    $this->postJson($uri, $payload, ['X-Livewire' => 'true'])->assertForbidden();
});

it('denies excluded panel authentication instead of classifying the route as public', function (): void {
    $panel = Panel::make()->id('coverage')->path('runtime-coverage')->authMiddleware([RuntimeAllowMiddleware::class]);
    $route = Route::get('/runtime-coverage/probe', static fn (): string => 'open');
    $route->middleware([RuntimeAllowMiddleware::class])->withoutMiddleware([RuntimeAllowMiddleware::class])->name('filament.coverage.probe');
    app()->tag([LateSecurityPanelExtender::class], AdminPanelExtender::TAG);
    expect(fn () => resolve(InstalledPanelRuntime::class)->extend($panel))->toThrow(RuntimeException::class);
    $this->get('/runtime-coverage/probe')->assertStatus(503);
});

it('denies Livewire updates after a partial runtime failure even if its route was not synchronised', function (string $failure): void {
    $panel = Panel::make()->id('failed-wire');
    Livewire::component('runtime.failed-counter', RuntimeCounterComponent::class);
    Route::get('/runtime-failed-wire', static fn (): string => Livewire::mount('runtime.failed-counter'))->name('filament.failed-wire.counter');
    $html = $this->get('/runtime-failed-wire')->assertOk()->getContent();
    throw_unless(is_string($html), RuntimeException::class, 'Expected component HTML.');
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    $snapshot = html_entity_decode($matches[1] ?? throw new RuntimeException('Missing Livewire snapshot.'), ENT_QUOTES);
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'increment', 'params' => []]]]]];
    $uri = resolve(HandleRequests::class)->getUpdateUri();
    $this->postJson($uri, $payload, ['X-Livewire' => 'true'])->assertOk();
    $extender = new class implements AdminPanelExtender
    {
        #[Override]
        public function extend(Panel $panel): void
        {
            $panel->authMiddleware([RuntimeWireBlockMiddleware::class]);
            throw new RuntimeException('incomplete');
        }
    };
    app()->instance('runtime.failed-wire', $extender);
    app()->tag(['runtime.failed-wire'], AdminPanelExtender::TAG);

    if ($failure === 'hook') {
        CapellCore::registerPackage('test/wire-hook-failure');
        CapellCore::markPackageInstalled('test/wire-hook-failure');
        $runtime = resolve(InstalledRuntimeLifecycle::class);
        $runtime->register(LatePanelRuntimeProvider::class, 'test/wire-hook-failure', 'runtime', static function (): void {
            throw new RuntimeException('incomplete hook');
        });
        expect(fn () => $runtime->refresh())->toThrow(RuntimeException::class);
    } else {
        expect(fn () => resolve(InstalledPanelRuntime::class)->extend($panel))->toThrow(RuntimeException::class);
    }

    Livewire::flushState();
    $this->postJson($uri, $payload, ['X-Livewire' => 'true'])->assertStatus(503);
})->with(['hook', 'panel']);

it('denies the current response when an install caller catches a partial panel failure', function (): void {
    $panel = Panel::make()->id('caught-failure');
    $extender = new class implements AdminPanelExtender
    {
        #[Override]
        public function extend(Panel $panel): void
        {
            throw new RuntimeException('incomplete security');
        }
    };
    app()->instance('runtime.caught-failure', $extender);
    app()->tag(['runtime.caught-failure'], AdminPanelExtender::TAG);
    Route::get('/runtime-caught-failure', static function () use ($panel): string {
        try {
            resolve(InstalledPanelRuntime::class)->extend($panel);
        } catch (RuntimeException) {
            return 'caught but unsafe';
        }

        return 'ready';
    })->name('filament.caught-failure.install');
    $this->get('/runtime-caught-failure')->assertStatus(503);
});

it('round three confines failures to admin while unrelated entry points run', function (string $failure): void {
    $panel = Filament::getPanel('admin');
    Route::get('/runtime-public', static fn (): string => 'public');
    $builder = new ApplicationBuilder(app());
    $routes = new ReflectionMethod($builder, 'buildRoutingCallback')->invoke($builder, null, null, null, '/up', 'api', null);
    $routes();
    if ($failure === 'hook') {
        CapellCore::registerPackage('test/failed-runtime');
        CapellCore::markPackageInstalled('test/failed-runtime');
        $runtime = resolve(InstalledRuntimeLifecycle::class);
        $runtime->register(LatePanelRuntimeProvider::class, 'test/failed-runtime', 'runtime', static function (): void {
            throw new RuntimeException('hook failure');
        });
        expect(fn () => $runtime->refresh())->toThrow(RuntimeException::class);
    } else {
        $extender = new class implements AdminPanelExtender
        {
            #[Override]
            public function extend(Panel $panel): void
            {
                throw new RuntimeException('panel failure');
            }
        };
        app()->instance('runtime.failure-scope', $extender);
        app()->tag(['runtime.failure-scope'], AdminPanelExtender::TAG);
        expect(fn () => resolve(InstalledPanelRuntime::class)->extend($panel))->toThrow(RuntimeException::class);
    }

    $this->get('/runtime-public')->assertOk();
    $this->get('/up')->assertOk();
    expect(Artisan::call('list'))->toBe(0);
    resolve(QueueManager::class)->connection('sync')->push(static function (): void {
        app()->instance('runtime.job-ran', true);
    });
    expect(resolve('runtime.job-ran'))->toBeTrue();
    $scheduled = false;
    $event = resolve(Schedule::class)->call(static function () use (&$scheduled): void {
        $scheduled = true;
    });
    $event->run(app());

    expect($scheduled)->toBeTrue();
    $this->get('/admin/login')->assertStatus(503);
    $inherited = clone app();
    expect($inherited->make(InstalledRuntimeLifecycle::class)->isUnavailable()
        || $inherited->make(InstalledPanelRuntime::class)->isUnavailable('admin'))->toBeTrue();
    $this->refreshApplication();
    Route::get('/admin/runtime-recovered', static fn (): string => 'recovered')->name('filament.admin.recovered');
    $this->get('/admin/runtime-recovered')->assertOk();
})->with(['hook', 'panel']);

it('round three permits inherited panel synchronisation after deactivation', function (string $operation): void {
    $panel = Filament::getPanel('admin');
    CapellCore::registerPackage('test/removed-runtime');
    CapellCore::markPackageInstalled('test/removed-runtime');
    CapellAdminPlugin::make()->synchronizePanelAdminSurface($panel);
    $owner = app();
    Container::setInstance(clone $owner);
    try {
        $package = CapellCore::getPackage('test/removed-runtime');
        if ($operation === 'uninstall') {
            UninstallPackageAction::run($package);
        } else {
            DisablePackageAction::run($package);
        }

        CapellAdminPlugin::make()->synchronizePanelAdminSurface($panel);
        expect(CapellCore::isPackageEnabled($package->name))->toBeFalse();
    } finally {
        Container::setInstance($owner);
    }
})->with(['disable', 'uninstall']);

it('round three honours an explicit full page only declaration during Livewire replay', function (): void {
    $panel = Panel::make()->id('full-page');
    Livewire::component('runtime.full-page-counter', RuntimeCounterComponent::class);
    Route::get('/runtime-full-page', static fn (): string => Livewire::mount('runtime.full-page-counter'))->name('filament.full-page.counter');
    $html = $this->get('/runtime-full-page')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $matches);
    $snapshot = html_entity_decode($matches[1] ?? throw new RuntimeException('Missing Livewire snapshot.'), ENT_QUOTES);
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'increment', 'params' => []]]]]];
    $extender = new class implements AdminPanelExtender, DeclaresFullPageOnlyMiddleware
    {
        #[Override]
        public function extend(Panel $panel): void
        {
            $panel->middleware([RuntimeFullPageOnlyMiddleware::class], isPersistent: false);
        }

        #[Override]
        public function fullPageOnlyMiddleware(): array
        {
            return [RuntimeFullPageOnlyMiddleware::class];
        }
    };
    app()->instance('runtime.full-page', $extender);
    app()->tag(['runtime.full-page'], AdminPanelExtender::TAG);

    resolve(InstalledPanelRuntime::class)->extend($panel);
    $this->get('/runtime-full-page')->assertForbidden();
    Livewire::flushState();
    $this->postJson(resolve(HandleRequests::class)->getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertOk();
});

it('keeps an unrelated panel available after a panel-only refresh fails', function (): void {
    $panel = Panel::make()->id('one-panel');
    Route::get('/runtime-other-panel', static fn (): string => 'other')->name('filament.other-panel.probe');
    $extender = new class implements AdminPanelExtender
    {
        #[Override]
        public function extend(Panel $panel): void
        {
            throw new RuntimeException('one panel only');
        }
    };
    app()->instance('runtime.one-panel', $extender);
    app()->tag(['runtime.one-panel'], AdminPanelExtender::TAG);

    expect(fn () => resolve(InstalledPanelRuntime::class)->extend($panel))->toThrow(RuntimeException::class);
    $this->get('/runtime-other-panel')->assertOk();
});

it('reconciles a real compiled cache for a non-interactive Marketplace installation', function (): void {
    $panel = Filament::getPanel('admin');
    CapellCore::registerPackage('test/panel-runtime');
    CapellCore::forcePackageInstalled('test/panel-runtime', false);
    app()->register(LatePanelRuntimeProvider::class);
    $path = app()->getCachedRoutesPath();
    $routes = resolve(Router::class)->getRoutes();
    throw_unless($routes instanceof RouteCollection, RuntimeException::class, 'Expected uncached fixture routes.');
    file_put_contents($path, '<?php return ' . var_export($routes->compile(), true) . ';');
    $panel->cacheComponents();
    try {
        InstallPackageAction::run(CapellCore::getPackage('test/panel-runtime'));
        config(['capell.multi_node' => false, 'octane.server' => null]);
        $notice = PropagateMarketplaceRuntimeStateAction::run(new MarketplaceInstallAttempt);
        expect($notice)->toBeNull()->and(file_exists($path))->toBeFalse()
            ->and(file_exists($panel->getComponentCachePath()))->toBeFalse();
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }

        $panel->clearCachedComponents();
    }
});
