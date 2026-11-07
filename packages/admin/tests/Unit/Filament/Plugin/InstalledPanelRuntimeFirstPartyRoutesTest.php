<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\MarketplaceCallbackRoutesProvider;
use Override;

/**
 * Every route Capell's own packages register records its owner as it is added: none is refused, and none
 * is a host route answering on an admin panel's path. A first-party admin route registered outside the
 * panel's route group fails here.
 */
final class InstalledPanelRuntimeFirstPartyRoutesTest extends AdminTestCase
{
    public function test_no_first_party_host_route_answers_on_a_panel_path(): void
    {
        $collisions = resolve(InstalledPanelRuntime::class)->panelPathCollisions();

        $this->assertSame([], $collisions, 'First-party host routes on an admin panel path: ' . implode(', ', $collisions));
    }

    public function test_no_first_party_route_is_refused_or_unrecorded(): void
    {
        $runtime = resolve(InstalledPanelRuntime::class);

        $this->assertSame([], $runtime->refusedRoutes());
        $this->assertSame([], $runtime->unrecordedRoutes());
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), MarketplaceCallbackRoutesProvider::class];
    }
}
