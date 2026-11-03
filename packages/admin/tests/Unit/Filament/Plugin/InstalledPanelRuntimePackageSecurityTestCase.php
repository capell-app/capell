<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateStandaloneCallbackRouteProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\StandaloneCallbackRouteProvider;
use Override;

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

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), ...$this->runtimeProviders()];
    }
}
