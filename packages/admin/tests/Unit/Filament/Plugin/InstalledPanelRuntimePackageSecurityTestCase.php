<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateStandaloneCallbackRouteProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StandaloneCallbackRouteProvider;
use Composer\Autoload\ClassLoader;
use Filament\Panel;
use Illuminate\Support\Facades\Route;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use StandalonePackage\PreviewController;

/** Request-level coverage when a package adds denying security middleware while the panel boots. */
abstract class InstalledPanelRuntimePackageSecurityTestCase extends AdminTestCase
{
    /** @return list<class-string> */
    abstract protected function runtimeProviders(): array;

    public function test_standalone_callback_carries_package_security_added_at_boot(): void
    {
        $this->actingAsAdmin();

        $this->get(StandaloneCallbackRouteProvider::URI)
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(StandaloneCallbackRouteProvider::BODY);
    }

    public function test_callback_registered_by_a_later_provider_carries_package_security(): void
    {
        $this->actingAsAdmin();
        app()->register(LateStandaloneCallbackRouteProvider::class);

        $this->get(LateStandaloneCallbackRouteProvider::URI)
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(StandaloneCallbackRouteProvider::BODY);
    }

    public function test_callback_that_excludes_package_security_fails_closed(): void
    {
        $this->actingAsAdmin();
        StandaloneCallbackRouteProvider::callback('/admin/standalone/excluding-callback', 'runtime-standalone.excluding-callback')
            ->withoutMiddleware(PackageSecurityDenyMiddleware::class);

        $this->get('/admin/standalone/excluding-callback')
            ->assertStatus(503)
            ->assertDontSee(StandaloneCallbackRouteProvider::BODY);
    }

    public function test_a_standalone_package_preview_without_panel_provenance_is_refused(): void
    {
        $loader = new ClassLoader(dirname(__DIR__, 3) . '/Fixtures/StandalonePackage');
        $loader->addPsr4('StandalonePackage\\', dirname(__DIR__, 3) . '/Fixtures/StandalonePackage/src');
        $loader->register();
        try {
            Route::get('/admin/ai-creator/sessions/preview', PreviewController::class);
            $this->actingAsAdmin()->get('/admin/ai-creator/sessions/preview')
                ->assertStatus(503)->assertDontSee('standalone preview served');
        } finally {
            $loader->unregister();
        }
    }

    public function test_a_package_route_registered_before_a_custom_panel_is_refused_when_it_synchronises(): void
    {
        $loader = new ClassLoader(dirname(__DIR__, 3) . '/Fixtures/StandalonePackage');
        $loader->addPsr4('StandalonePackage\\', dirname(__DIR__, 3) . '/Fixtures/StandalonePackage/src');
        $loader->register();
        try {
            $route = Route::get('/custom-panel/preview-before', PreviewController::class);
            $runtime = resolve(InstalledPanelRuntime::class);
            $runtime->extend(Panel::make()->id('custom')->path('custom-panel'));

            $this->assertTrue($runtime->isRouteRefused($route));
            $this->get('/custom-panel/preview-before')->assertStatus(503)->assertDontSee('standalone preview served');
        } finally {
            $loader->unregister();
        }
    }

    public function test_a_package_route_registered_after_a_custom_panel_is_refused(): void
    {
        $loader = new ClassLoader(dirname(__DIR__, 3) . '/Fixtures/StandalonePackage');
        $loader->addPsr4('StandalonePackage\\', dirname(__DIR__, 3) . '/Fixtures/StandalonePackage/src');
        $loader->register();
        try {
            $runtime = resolve(InstalledPanelRuntime::class);
            $runtime->extend(Panel::make()->id('custom')->path('custom-panel'));
            Route::get('/custom-panel/preview-after', PreviewController::class);

            $this->get('/custom-panel/preview-after')->assertStatus(503)->assertDontSee('standalone preview served');
        } finally {
            $loader->unregister();
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_a_concrete_package_host_overlapping_a_parameterised_panel_is_refused(bool $beforePanel): void
    {
        $loader = new ClassLoader(dirname(__DIR__, 3) . '/Fixtures/StandalonePackage');
        $loader->addPsr4('StandalonePackage\\', dirname(__DIR__, 3) . '/Fixtures/StandalonePackage/src');
        $loader->register();
        try {
            $runtime = resolve(InstalledPanelRuntime::class);
            $panel = Panel::make()->id('tenant-panel')->path('tenant-admin')->domains(['{tenant}.example.test']);
            if (! $beforePanel) {
                $runtime->extend($panel);
            }

            Route::domain('shop.example.test')->get('/tenant-admin/package-preview', PreviewController::class);
            if ($beforePanel) {
                $runtime->extend($panel);
            }

            $this->get('http://shop.example.test/tenant-admin/package-preview')->assertStatus(503)->assertDontSee('standalone preview served');
        } finally {
            $loader->unregister();
        }
    }

    public function test_public_routes_registered_before_and_after_a_root_panel_synchronises_are_served(): void
    {
        $route = Route::get('/root-panel-preview', static fn (): string => 'root preview served');
        $runtime = resolve(InstalledPanelRuntime::class);
        $runtime->extend(Panel::make()->id('root')->path(''));
        Route::get('/root-panel-after', static fn (): string => 'later preview served');

        $this->assertFalse($runtime->isRouteRefused($route));
        $this->get('/root-panel-preview')->assertOk()->assertSee('root preview served');
        $this->get('/root-panel-after')->assertOk()->assertSee('later preview served');
        $this->get('/root-panel-missing')->assertNotFound();
        $this->get('/admin/login')->assertOk();
    }

    public function test_an_old_inferred_host_record_is_refused_when_a_custom_panel_synchronises(): void
    {
        $route = Route::get('/custom-panel/legacy-preview', static fn (): string => 'legacy preview served');
        PanelOwnership::host()->recordOn($route);
        $runtime = resolve(InstalledPanelRuntime::class);
        $runtime->extend(Panel::make()->id('custom')->path('custom-panel'));

        $this->assertTrue($runtime->isRouteRefused($route));
        $this->get('/custom-panel/legacy-preview')->assertStatus(503)->assertDontSee('legacy preview served');
    }

    public function test_a_standalone_package_preview_with_panel_provenance_runs_package_security(): void
    {
        $loader = new ClassLoader(dirname(__DIR__, 3) . '/Fixtures/StandalonePackage');
        $loader->addPsr4('StandalonePackage\\', dirname(__DIR__, 3) . '/Fixtures/StandalonePackage/src');
        $loader->register();
        try {
            AdminRouteProvenance::panel('admin', static function (): void {
                Route::get('/admin/ai-creator/sessions/declared', PreviewController::class);
            });
            $this->actingAsAdmin()->get('/admin/ai-creator/sessions/declared')
                ->assertForbidden()->assertSee(PackageSecurityDenyMiddleware::BODY)->assertDontSee('standalone preview served');
        } finally {
            $loader->unregister();
        }
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), ...$this->runtimeProviders()];
    }
}
