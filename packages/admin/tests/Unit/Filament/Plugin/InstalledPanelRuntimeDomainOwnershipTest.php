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
 * A route belongs to the panel whose route group registered it, on the domain it was registered with. A
 * panel served on its own domains never serves a route registered without one of them, and a route that
 * merely shares a panel's path, or names or declares a panel after it was registered, is not that panel's.
 */
final class InstalledPanelRuntimeDomainOwnershipTest extends AdminTestCase
{
    private const string DESK = 'http://' . DomainCapellPanelProvider::DOMAIN;

    /** A host's public route sharing a domain panel's path serves guests on hosts that panel is not served on. */
    public function test_a_public_route_without_a_domain_under_a_domain_panels_path_stays_public_off_its_domain(): void
    {
        Route::get('/desk/runtime-public', static fn (): string => 'public page')->middleware('web');

        $this->get('/desk/runtime-public')->assertOk()->assertSee('public page');
        // On the panel's own domain the path is the panel's, and a route the panel did not register is refused.
        $this->get(self::DESK . '/desk/runtime-public')->assertStatus(503)->assertDontSee('public page');
    }

    public function test_admin_code_without_a_domain_under_a_domain_panels_path_is_refused(): void
    {
        Route::get('/desk/runtime-admin/{initials}.svg', AdminAvatarController::class)->middleware('web');

        $this->get('/desk/runtime-admin/AB.svg')->assertStatus(503);
        $this->get(self::DESK . '/desk/runtime-admin/AB.svg')->assertStatus(503);
    }

    /** Naming a route for a domain panel after it was registered does not make it that panel's. */
    public function test_a_route_named_for_a_domain_panel_after_registration_is_refused_on_every_host(): void
    {
        Route::get('/runtime-named-probe', static fn (): string => 'probe')->middleware('web')->name('filament.desk.runtime-probe');

        $this->get('http://' . OtherDomainCapellPanelProvider::DOMAIN . '/runtime-named-probe')->assertStatus(503)->assertDontSee('probe');
        $this->get(self::DESK . '/runtime-named-probe')->assertStatus(503)->assertDontSee('probe');
        $this->get('/runtime-named-probe')->assertStatus(503)->assertDontSee('probe');
    }

    /** A route registered in a domain panel's group without that panel's domain answers other hosts, so it is refused. */
    public function test_a_route_registered_in_a_domain_panel_without_its_domain_is_refused(): void
    {
        Route::middleware(['panel:desk', 'web'])->group(static function (): void {
            Route::get('/runtime-undomained-desk', static fn (): string => 'desk route');
        });

        $this->get('/runtime-undomained-desk')->assertStatus(503)->assertDontSee('desk route');
        $this->get(self::DESK . '/runtime-undomained-desk')->assertStatus(503)->assertDontSee('desk route');
    }

    /** A route registered in a domain panel's group on its domain runs that panel's authentication. */
    public function test_a_route_registered_in_a_domain_panel_on_its_domain_runs_that_panels_authentication(): void
    {
        Route::domain(DomainCapellPanelProvider::DOMAIN)->middleware(['panel:desk', 'web'])->group(static function (): void {
            Route::get('/desk/runtime-owned', static fn (): string => 'owned');
        });

        $this->get(self::DESK . '/desk/runtime-owned')->assertRedirect(self::DESK . '/desk/login');
    }

    /**
     * The domainless admin panel and a domain panel can share a path: a route the domain panel registered on its
     * domain is the domain panel's, not the admin panel's, though the admin panel's path holds it too.
     */
    public function test_a_domain_panels_route_under_the_domainless_panels_path_belongs_to_the_domain_panel(): void
    {
        Route::domain(DomainCapellPanelProvider::DOMAIN)->middleware(['panel:desk', 'web'])->group(static function (): void {
            Route::get('/admin/runtime-desk-shared', static fn (): string => 'desk shared');
        });

        $this->get(self::DESK . '/admin/runtime-desk-shared')->assertRedirect(self::DESK . '/desk/login');
    }

    /** A route constrained to a domain panel's domain but not registered by the panel is refused on that domain. */
    public function test_a_route_on_a_domain_panels_domain_not_registered_by_it_is_refused(): void
    {
        Route::domain(DomainCapellPanelProvider::DOMAIN)->get('/desk/runtime-unregistered', static fn (): string => 'unregistered')->middleware('web');
        Route::domain(DomainCapellPanelProvider::DOMAIN)->get('/admin/runtime-unregistered', static fn (): string => 'unregistered')->middleware('web');

        $this->get(self::DESK . '/desk/runtime-unregistered')->assertStatus(503)->assertDontSee('unregistered');
        // The domainless admin panel serves its path on every host, so a host route there is refused too.
        $this->get(self::DESK . '/admin/runtime-unregistered')->assertStatus(503)->assertDontSee('unregistered');
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
