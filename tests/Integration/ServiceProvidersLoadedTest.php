<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;

it('service providers are loaded', function (): void {
    $providers = app()->getLoadedProviders();
    expect($providers)
        ->toHaveKey(CapellServiceProvider::class)
        ->toHaveKey(AdminServiceProvider::class)
        ->toHaveKey(FrontendServiceProvider::class)
        ->toHaveKey(InstallerServiceProvider::class)
        ->toHaveKey(MarketplaceServiceProvider::class);
});
