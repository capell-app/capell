<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\SidebarCollapseEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Settings\AdminSettings;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\TestAdminPanelExtender;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

use function Pest\Laravel\get;

use Symfony\Component\HttpFoundation\Response;

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

it('repairs route middleware after a failed extender is retried', function (): void {
    $panel = Panel::make()->id('runtime-retry')->authMiddleware(['auth']);
    $route = Route::get('runtime-retry/probe', static fn (): string => 'probe')
        ->middleware(['auth'])->name('filament.runtime-retry.probe');
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
    resolve(InstalledPanelRuntime::class)->extend($panel);

    expect($route->middleware())->toContain(LateSecurityMiddleware::class);
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

final class LateSecurityPanelExtender implements AdminPanelExtender
{
    #[Override]
    public function extend(Panel $panel): void
    {
        $panel->authMiddleware([LateSecurityMiddleware::class], isPersistent: true);
    }
}

final class LateSecurityMiddleware
{
    public function handle(): Response
    {
        return redirect('/admin/change-password');
    }
}

final class LatePanelRuntimeProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('test/panel-runtime', 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        $this->app->tag([LateSecurityPanelExtender::class], AdminPanelExtender::TAG);
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::page(LateRuntimePage::class));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(LateRuntimeResource::class, 'Runtime'));
        CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::widget(LateRuntimeWidget::class));
    }
}

it('adds late panel pages resources and widgets once and registers their Livewire components', function (): void {
    $panel = Filament::getPanel('admin');
    CapellCore::registerPackage('test/panel-runtime');
    CapellCore::forcePackageInstalled('test/panel-runtime', false);
    app()->register(LatePanelRuntimeProvider::class);
    InstallPackageAction::run(CapellCore::getPackage('test/panel-runtime'));
    CapellAdminPlugin::make()->synchronizePanelAdminSurface($panel);
    expect($panel->getPages())->toContain(LateRuntimePage::class)
        ->and($panel->getResources())->toContain(LateRuntimeResource::class)
        ->and($panel->getWidgets())->toContain(LateRuntimeWidget::class);
    foreach (['pages' => LateRuntimePage::class, 'resources' => LateRuntimeResource::class, 'widgets' => LateRuntimeWidget::class] as $property => $class) {
        $entries = new ReflectionProperty($panel, $property)->getValue($panel);
        expect(array_count_values($entries)[$class] ?? 0)->toBe(1);
    }

    $finder = resolve('livewire.finder');
    $definitions = new ReflectionProperty($finder, 'classComponents')->getValue($finder);
    expect($definitions)->toContain(LateRuntimePage::class, LateRuntimeWidget::class);
});

final class LateRuntimePage extends Page {}

final class LateRuntimeResource extends Resource {}

final class LateRuntimeWidget extends Widget {}
