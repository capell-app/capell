<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\DomainCapellPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\OtherDomainCapellPanelProvider;
use Illuminate\Support\Facades\Route;
use Override;

/**
 * A panel served on its own domains owns a route under its path only when the route is constrained to
 * one of those domains. A route with no domain is not assumed to belong to every domain's panel.
 */
final class InstalledPanelRuntimeDomainOwnershipTest extends AdminTestCase
{
    /** A host's public route that happens to share a domain panel's path keeps serving guests on every host. */
    public function test_a_public_route_without_a_domain_under_a_domain_panels_path_stays_public(): void
    {
        Route::get('/desk/runtime-public', static fn (): string => 'public page')->middleware('web');

        $this->get('/desk/runtime-public')->assertOk()->assertSee('public page');
    }

    /** Admin code without a domain under a domain panel's path cannot be tied to one panel, so it is refused. */
    public function test_admin_code_without_a_domain_under_a_domain_panels_path_is_refused(): void
    {
        Route::get('/desk/runtime-admin/{initials}.svg', AdminAvatarController::class)->middleware('web');

        $this->get('/desk/runtime-admin/AB.svg')->assertStatus(503);
        $this->get('http://' . DomainCapellPanelProvider::DOMAIN . '/desk/runtime-admin/AB.svg')->assertStatus(503);
    }

    /** A route named for a domain panel is only served on that panel's domains. */
    public function test_a_route_named_for_a_domain_panel_is_not_served_on_another_host(): void
    {
        Route::get('/runtime-named-probe', static fn (): string => 'probe')->middleware('web')->name('filament.desk.runtime-probe');

        $this->get('http://' . OtherDomainCapellPanelProvider::DOMAIN . '/runtime-named-probe')->assertNotFound();
        $this->get('http://' . DomainCapellPanelProvider::DOMAIN . '/runtime-named-probe')->assertRedirect('http://' . DomainCapellPanelProvider::DOMAIN . '/desk/login');
    }

    /** A route constrained to a domain panel's domain under its path is that panel's, and runs its authentication. */
    public function test_a_route_on_a_domain_panels_domain_runs_that_panels_authentication(): void
    {
        Route::domain(DomainCapellPanelProvider::DOMAIN)->get('/desk/runtime-owned', static fn (): string => 'owned')->middleware('web');

        $this->get('http://' . DomainCapellPanelProvider::DOMAIN . '/desk/runtime-owned')
            ->assertRedirect('http://' . DomainCapellPanelProvider::DOMAIN . '/desk/login');
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = parent::getPackageProviders($app);
        $position = array_search(AdminPanelProvider::class, $providers, true);
        array_splice($providers, $position === false ? count($providers) : $position + 1, 0, [DomainCapellPanelProvider::class, OtherDomainCapellPanelProvider::class]);

        return $providers;
    }
}
