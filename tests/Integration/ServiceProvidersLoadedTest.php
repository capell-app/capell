<?php

declare(strict_types=1);

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Marketplace\Providers\MarketplaceServiceProvider;

test('service providers are loaded', function (): void {
    $providers = app()->getLoadedProviders();
    expect($providers)
        ->toHaveKey(CapellServiceProvider::class)
        ->toHaveKey(AdminServiceProvider::class)
        ->toHaveKey(FrontendServiceProvider::class)
        ->toHaveKey(InstallerServiceProvider::class)
        ->toHaveKey(MarketplaceServiceProvider::class);
});

test('root package does not replace split Capell packages', function (): void {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($composer, 'replace', []))
        ->not->toHaveKey('capell-app/core')
        ->not->toHaveKey('capell-app/admin')
        ->not->toHaveKey('capell-app/frontend')
        ->not->toHaveKey('capell-app/installer')
        ->not->toHaveKey('capell-app/marketplace')
        ->and(data_get($composer, 'extra.laravel.providers'))->toEqualCanonicalizing([
            CapellServiceProvider::class,
            AdminServiceProvider::class,
            FrontendServiceProvider::class,
            InstallerServiceProvider::class,
            MarketplaceServiceProvider::class,
        ]);
});
