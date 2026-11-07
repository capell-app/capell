<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\MarketplaceCallbackRoutesProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PasswordPolicyStyleRuntimeProvider;
use Override;

/** Password-policy style middleware added at boot reaches the real Marketplace callback routes. */
final class InstalledPanelRuntimeMarketplaceCallbackSecurityTest extends AdminTestCase
{
    public function test_marketplace_callbacks_carry_password_policy_style_middleware(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/marketplace/connection/callback')->assertRedirect('/admin/change-password');
        $this->get('/admin/marketplace/install-flow/callback')->assertRedirect('/admin/change-password');
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), PasswordPolicyStyleRuntimeProvider::class, MarketplaceCallbackRoutesProvider::class];
    }
}
