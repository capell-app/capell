<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\BootAuthenticationRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateWebGroupMarkerMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\WebGroupMarkerMiddleware;
use Illuminate\Routing\Router;
use Override;

/** An application's web group carries middleware without a router priority, as Laravel Boost adds. */
final class InstalledPanelRuntimeWebGroupOrderTest extends AdminTestCase
{
    public function test_admin_api_routes_run_unprioritised_web_group_middleware_after_panel_security(): void
    {
        $this->getJson(route('capell-admin.api.page-tree'))
            ->assertUnauthorized()
            ->assertHeaderMissing(WebGroupMarkerMiddleware::HEADER);

        $this->actingAsAdmin();

        $this->getJson(route('capell-admin.api.page-tree'))
            ->assertOk()
            ->assertHeader(WebGroupMarkerMiddleware::HEADER, 'ran');
    }

    public function test_middleware_a_package_adds_to_the_web_group_after_boot_also_runs_after_panel_security(): void
    {
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', LateWebGroupMarkerMiddleware::class);

        $this->getJson(route('capell-admin.api.page-tree'))
            ->assertUnauthorized()
            ->assertHeaderMissing(LateWebGroupMarkerMiddleware::HEADER);

        $this->actingAsAdmin();

        $this->getJson(route('capell-admin.api.page-tree'))
            ->assertOk()
            ->assertHeader(LateWebGroupMarkerMiddleware::HEADER, 'ran');
    }

    #[Override]
    protected function defineEnvironment(mixed $app): void
    {
        parent::defineEnvironment($app);

        // After every provider has booted, so a provider that redefines the web group cannot drop it.
        $app->booted(static function (mixed $app): void {
            $app->make(Router::class)->pushMiddlewareToGroup('web', WebGroupMarkerMiddleware::class);
        });
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), BootAuthenticationRuntimeProvider::class];
    }
}
