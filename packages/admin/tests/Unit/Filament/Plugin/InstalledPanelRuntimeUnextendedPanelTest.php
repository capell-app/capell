<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Support\ComposerPsr4Map;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\ForeignPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\HostCompanionAdminController;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\HostCompanionWorkspacePage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\InspectedRouteController;
use Capell\CompanionFixture\CompanionAdminController;
use Capell\CompanionFixture\CompanionWorkspacePage;
use Composer\Autoload\ClassLoader;
use Filament\Facades\Filament;
use Filament\Http\Controllers\RedirectToHomeController;
use Illuminate\Log\LogManager;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A panel registered without the Capell plugin keeps serving its own routes under its own stack, but cannot
 * serve Capell's admin code, or the admin code of Capell's companion packages, which depends on the package
 * security only the Capell plugin adds. A host's own subclasses of that code are the host's to mount.
 */
final class InstalledPanelRuntimeUnextendedPanelTest extends AdminTestCase
{
    private const string HOST_ROOT_NAMESPACE = 'Capell\\HostRoot\\';

    private const string HOST_ROOT_ROUTES = 'routes/capell-host-root.php';

    private static int $mapReads = 0;

    private static ?MockInterface $log = null;

    public function test_a_panel_without_the_capell_plugin_refuses_capell_admin_code(): void
    {
        $this->portalRoute('/portal/avatar/{initials}', AdminAvatarController::class);

        $this->get('/portal/avatar/AB')->assertStatus(503)->assertDontSee('<svg', false);
    }

    public function test_a_panel_without_the_capell_plugin_refuses_capell_resource_pages(): void
    {
        // As a host panel that discovers Capell's resources without the plugin would register them.
        $this->portalRoute('/portal/pages', ListPages::class);

        $this->get('/portal/pages')->assertStatus(503);
    }

    public function test_a_panel_without_the_capell_plugin_keeps_serving_its_own_routes(): void
    {
        $this->portalRoute('/portal/avatar/{initials}', AdminAvatarController::class);
        $this->portalRoute('/portal/inspected', InspectedRouteController::class);

        $this->get('/portal/inspected')->assertOk()->assertSee('inspected route served');
    }

    public function test_a_panel_without_the_capell_plugin_keeps_serving_filaments_own_routes(): void
    {
        $this->portalRoute('/portal/home', RedirectToHomeController::class);

        // Not refused: Filament answers under the panel's own stack.
        $this->assertNotSame(Response::HTTP_SERVICE_UNAVAILABLE, $this->get('/portal/home')->baseResponse->getStatusCode());
    }

    /** @param class-string $action */
    #[TestWith(['/portal/companion', CompanionAdminController::class])]
    #[TestWith(['/portal/companion-workspace', CompanionWorkspacePage::class])]
    public function test_a_panel_without_the_capell_plugin_refuses_companion_package_admin_code(string $uri, string $action): void
    {
        $this->portalRoute($uri, $action);

        $this->get($uri)->assertStatus(503)->assertDontSee(CompanionAdminController::BODY)->assertDontSee(CompanionWorkspacePage::BODY);
    }

    public function test_a_panel_without_the_capell_plugin_refuses_companion_livewire_pages(): void
    {
        Route::name('filament.portal.')->middleware('panel:portal')->group(static fn () => Route::livewire('/portal/companion-livewire', CompanionWorkspacePage::class));

        $this->get('/portal/companion-livewire')->assertStatus(503)->assertDontSee(CompanionWorkspacePage::BODY);
    }

    public function test_a_panel_without_the_capell_plugin_refuses_companion_route_files(): void
    {
        Route::name('filament.portal.')->middleware('panel:portal')->group(dirname(__DIR__, 3) . '/Fixtures/CompanionPackage/routes/portal.php');

        $this->get('/portal/companion-closure')->assertStatus(503)->assertDontSee('companion route file served');
    }

    /** A cached closure is never unserialised to be read, so the companion code it runs is recorded when it is registered. */
    public function test_a_panel_without_the_capell_plugin_refuses_cached_companion_route_files(): void
    {
        Route::name('filament.portal.')->middleware('panel:portal')->group(dirname(__DIR__, 3) . '/Fixtures/CompanionPackage/routes/portal.php');
        Route::name('filament.portal.')->middleware('panel:portal')->group(static function (): void {
            // Its own line: a serialised closure is found by its line.
            Route::get('/portal/host-closure', static fn (): string => 'host closure served');
        });
        $this->cacheRoutes();

        $this->get('/portal/companion-closure')->assertStatus(503)->assertDontSee('companion route file served');
        $this->get('/portal/host-closure')->assertOk()->assertSee('host closure served');
    }

    /** Without Composer's map the application still boots, and the panel refuses what it cannot tell apart from Capell code. */
    public function test_a_panel_without_the_capell_plugin_refuses_its_closures_when_composers_map_cannot_be_read(): void
    {
        Route::name('filament.portal.')->middleware('panel:portal')->group(static function (): void {
            Route::get('/portal/host-closure', static fn (): string => 'host closure served');
            Route::get('/portal/other-host-closure', static fn (): string => 'other host closure served');
        });

        $this->get('/portal/host-closure')->assertStatus(503)->assertDontSee('host closure served');
        $this->get('/portal/other-host-closure')->assertStatus(503)->assertDontSee('other host closure served');
        $this->assertSame(1, self::$mapReads);
        $this->assertInstanceOf(MockInterface::class, self::$log);
        self::$log->shouldHaveReceived('error')->withArgs(static fn (string $message): bool => $message === __('capell-admin::message.capell_package_map_unreadable'))->once();
    }

    /** A host mapping a Capell namespace onto its own root keeps its own route files. */
    #[DefineEnvironment('mapCapellNamespaceOntoTheHostRoot')]
    public function test_a_panel_without_the_capell_plugin_serves_a_host_mapping_a_capell_namespace_onto_its_root(): void
    {
        try {
            Route::name('filament.portal.')->middleware('panel:portal')->group(base_path(self::HOST_ROOT_ROUTES));

            $this->get('/portal/host-root')->assertOk()->assertSee('host root route served');
        } finally {
            array_values(ClassLoader::getRegisteredLoaders())[0]->setPsr4(self::HOST_ROOT_NAMESPACE, []);
            File::delete(base_path(self::HOST_ROOT_ROUTES));
        }
    }

    /** @param class-string $action */
    #[TestWith(['/portal/host-companion', HostCompanionAdminController::class, CompanionAdminController::BODY])]
    #[TestWith(['/portal/host-companion-workspace', HostCompanionWorkspacePage::class, CompanionWorkspacePage::BODY])]
    public function test_a_panel_without_the_capell_plugin_serves_host_subclasses_of_companion_code(string $uri, string $action, string $body): void
    {
        $this->portalRoute($uri, $action);

        $this->get($uri)->assertOk()->assertSee($body);
    }

    public function test_the_capell_panel_still_serves_companion_package_pages_under_its_security(): void
    {
        $panel = Filament::getPanel('admin');
        Route::prefix('admin')->name('filament.admin.')->middleware($panel->getMiddleware())->group(static fn () => Route::get('/companion-workspace', CompanionWorkspacePage::class));

        // Not refused: Capell's panel security answers, sending the signed-out visitor to sign in.
        $this->get('/admin/companion-workspace')->assertRedirect('/admin/login')->assertDontSee(CompanionWorkspacePage::BODY);
    }

    /** For the unreadable-map test, Composer's map throws when read, and logging is observed, from before the application boots. */
    #[Override]
    protected function overrideApplicationBindings($app): array
    {
        if ($this->name() === 'test_a_panel_without_the_capell_plugin_refuses_its_closures_when_composers_map_cannot_be_read') {
            self::$mapReads = 0;
            $log = Mockery::spy(LogManager::class, [$app])->makePartial();
            self::$log = $log;
            $app->instance('log', $log);
            $app->instance(ComposerPsr4Map::class, new ComposerPsr4Map(static function (): never {
                self::$mapReads++;

                throw new RuntimeException('The autoloader cannot be read.');
            }));
        }

        return parent::overrideApplicationBindings($app);
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = parent::getPackageProviders($app);
        $position = array_search(AdminPanelProvider::class, $providers, true);
        array_splice($providers, $position === false ? count($providers) : $position + 1, 0, [ForeignPanelProvider::class]);

        return $providers;
    }

    /** Map a Capell namespace onto the host's own root before Capell reads Composer's map. */
    protected function mapCapellNamespaceOntoTheHostRoot(mixed $app): void
    {
        File::ensureDirectoryExists(base_path('src'));
        File::put(base_path(self::HOST_ROOT_ROUTES), "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/portal/host-root', static fn (): string => 'host root route served');\n");
        array_values(ClassLoader::getRegisteredLoaders())[0]->addPsr4(self::HOST_ROOT_NAMESPACE, base_path('src'));
    }

    /** Write and load the route cache exactly as `route:cache` does. */
    private function cacheRoutes(): void
    {
        $routes = Route::getRoutes();
        $this->assertInstanceOf(RouteCollection::class, $routes);
        foreach ($routes->getRoutes() as $route) {
            $route->prepareForSerialization();
        }

        $file = storage_path('framework/testing-routes-' . bin2hex(random_bytes(6)) . '.php');
        File::ensureDirectoryExists(dirname($file));
        File::put($file, '<?php return ' . var_export($routes->compile(), true) . ';');
        try {
            Route::setCompiledRoutes(require $file);
        } finally {
            File::delete($file);
        }
    }

    /** @param class-string $controller */
    private function portalRoute(string $uri, string $controller): void
    {
        $route = null;
        Route::name('filament.portal.')->middleware('panel:portal')->group(static function () use (&$route, $uri, $controller): void {
            $route = Route::get($uri, $controller);
        });
        $this->assertInstanceOf(\Illuminate\Routing\Route::class, $route);
        $this->assertSame('portal', PanelOwnership::of($route)?->panel);
    }
}
