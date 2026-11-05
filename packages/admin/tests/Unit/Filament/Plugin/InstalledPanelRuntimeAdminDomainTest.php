<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\MarketplaceCallbackRoutesProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Override;

/**
 * With the admin entrypoint served on its own domain, first-party admin routes registered outside
 * Filament's panel group are the admin panel's on that domain, not refused as off its domains.
 */
final class InstalledPanelRuntimeAdminDomainTest extends AdminTestCase
{
    private const string ADMIN = 'http://admin.example.test';

    public function test_marketplace_callbacks_are_served_by_the_admin_panel_on_its_domain(): void
    {
        $this->get(self::ADMIN . '/admin/marketplace/connection/callback')->assertRedirect(self::ADMIN . '/admin/login');
        $this->get(self::ADMIN . '/admin/marketplace/install-flow/callback')->assertRedirect(self::ADMIN . '/admin/login');
    }

    public function test_core_admin_routes_are_served_by_the_admin_panel_on_its_domain(): void
    {
        $this->get(self::ADMIN . '/admin/extension-asset')->assertRedirect(self::ADMIN . '/admin/login');
    }

    public function test_first_party_admin_routes_are_not_answered_on_another_host(): void
    {
        $this->get('http://elsewhere.example.test/admin/marketplace/connection/callback')->assertNotFound();
    }

    public function test_routes_registered_on_the_admin_entrypoint_answer_only_on_its_domain(): void
    {
        AdminRouteProvenance::entrypoint(static function (): void {
            Route::get('runtime-entrypoint/{initials}.svg', AdminAvatarController::class);
        });

        $this->get(self::ADMIN . '/admin/runtime-entrypoint/AB.svg')->assertRedirect(self::ADMIN . '/admin/login');
        $this->get('http://elsewhere.example.test/admin/runtime-entrypoint/AB.svg')->assertNotFound();
    }

    #[Override]
    protected function getEnvironmentSetUp(mixed $app): void
    {
        parent::getEnvironmentSetUp($app);
        $app->make(Repository::class)->set('capell-admin.domain', 'admin.example.test');
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), MarketplaceCallbackRoutesProvider::class];
    }
}
